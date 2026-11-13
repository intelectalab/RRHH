<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\PositionRequestStateMachine;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\PositionRequest;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Vigencia\EffectiveDated;
use App\Shared\Workflow\ApprovalRequests;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS-3 · Solicitud y aprobación de nuevas posiciones (ME-04).
 *
 * 1) El manager o RRHH registra la solicitud en borrador (puesto, unidad, motivo, cantidad, fecha y
 *    costo estimado por plaza). 2) Al enviarla se valida la estructura y el presupuesto disponible de la
 *    unidad (RN-POS-05: si lo supera queda marcada over_budget y el flujo puede exigir un nivel
 *    adicional) y se inicia el flujo de aprobación configurado para `position_request`. 3) El motor
 *    devuelve la decisión por PositionRequestOutcome en la misma transacción: aprobada crea sus
 *    posiciones en «aprobada» (trazadas con position_request_id); rechazada guarda el motivo y el motor
 *    notifica al solicitante.
 */
final class PositionRequestService
{
    public const SUBJECT_TYPE = 'position_request';

    /** Campos editables en borrador: nombre de la API => columna. */
    private const FIELDS = [
        'legal_entity_id' => 'legal_entity_id', 'org_unit_id' => 'org_unit_id', 'job_profile_id' => 'job_profile_id',
        'site_id' => 'site_id', 'title' => 'title', 'cost_center_id' => 'cost_center_id',
        'reports_to_position_id' => 'reports_to_position_id', 'salary_band_id' => 'salary_band_id',
        'quantity' => 'quantity', 'justification' => 'justification', 'kind' => 'kind',
        'replaces_employment_id' => 'replaces_employment_id', 'needed_by' => 'needed_by',
    ];

    /** Acción → evento del outbox. */
    private const EVENTS = [
        'submit' => DomainEventTypes::POSITION_REQUESTED,
        'cancel' => DomainEventTypes::POSITION_REQUEST_CANCELLED,
        'approve' => DomainEventTypes::POSITION_REQUEST_APPROVED,
        'reject' => DomainEventTypes::POSITION_REQUEST_REJECTED,
    ];

    public function __construct(
        private readonly PositionRequestStateMachine $machine,
        private readonly StructureAvailability $structure,
        private readonly ApprovalRequests $approvals,
        private readonly HeadcountService $headcount,
        private readonly PositionService $positions,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ------------------------------------------------------------------ Presentación

    /**
     * El costo estimado (estimated_cost) viaja aquí; el controlador lo quita sin permiso de costos,
     * salvo al propio solicitante, que lo registró.
     *
     * @return array<string, mixed>
     */
    public function present(PositionRequest $request, bool $detail = false): array
    {
        $name = fn (string $table, ?string $id, string $col = 'name') => $id === null ? null : DB::table($table)->where('id', $id)->value($col);
        $row = [
            'id' => $request->id,
            'requested_by_user_id' => $request->requested_by_user_id,
            'requested_by_name' => $name('users', $request->requested_by_user_id, 'display_name'),
            'legal_entity_id' => $request->legal_entity_id,
            'legal_entity_name' => $name('legal_entities', $request->legal_entity_id, 'legal_name'),
            'org_unit_id' => $request->org_unit_id,
            'org_unit_name' => $name('org_units', $request->org_unit_id),
            'job_profile_id' => $request->job_profile_id,
            'job_profile_name' => $name('job_profiles', $request->job_profile_id),
            'site_id' => $request->site_id,
            'cost_center_id' => $request->cost_center_id,
            'reports_to_position_id' => $request->reports_to_position_id,
            'salary_band_id' => $request->salary_band_id,
            'title' => $request->title,
            'quantity' => $request->quantity,
            'justification' => $request->justification,
            'kind' => $request->kind,
            'replaces_employment_id' => $request->replaces_employment_id,
            'needed_by' => $request->needed_by?->toDateString(),
            'status' => $request->status,
            'over_budget' => $request->over_budget,
            'approval_request_id' => $request->workflow_instance_id,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_reason' => $request->decision_reason,
            'allowed_actions' => $this->machine->manualActions($request->status),
            'version' => $request->lock_version,
            'created_at' => $request->created_at->toIso8601String(),
            'updated_at' => $request->updated_at->toIso8601String(),
        ];
        if ($request->estimated_cost !== null && $request->currency !== null) {
            $row['estimated_cost'] = ['amount' => $request->estimated_cost, 'currency' => $request->currency];
        }
        if ($detail) {
            $row['approval'] = $request->workflow_instance_id === null ? null : $this->approvals->summary($request->workflow_instance_id);
            $row['budget'] = $this->budgetSummary($request);
            $row['positions'] = Position::query()->where('position_request_id', $request->id)->orderBy('code')->get()
                ->map(fn (Position $p) => ['id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'status' => $p->status])->values()->all();
        }

        return $row;
    }

    /**
     * Plazas disponibles en el presupuesto de la unidad (sin montos: el costo disponible es sensible).
     *
     * @return array{has_budget: bool, budgeted_positions: int|null, committed_positions: int, available_positions: int|null}
     */
    public function budgetSummary(PositionRequest $request): array
    {
        $a = $this->headcount->availability($request->org_unit_id, $this->effectiveDay($request));

        return ['has_budget' => $a['has_budget'], 'budgeted_positions' => $a['budgeted_positions'],
            'committed_positions' => $a['committed_positions'], 'available_positions' => $a['available_positions']];
    }

    // ------------------------------------------------------------------ Escritura

    /**
     * @param  array<string, mixed>  $input  ya validado
     */
    public function create(array $input, string $userId): PositionRequest
    {
        $values = $this->columns($input);
        $values['legal_entity_id'] ??= DB::table('org_units')->where('id', $values['org_unit_id'] ?? null)->value('legal_entity_id');
        $this->check($values, null);

        return DB::transaction(function () use ($values, $userId): PositionRequest {
            $request = PositionRequest::create($values + ['requested_by_user_id' => $userId, 'status' => 'draft']);
            $request->refresh();
            $this->audit->record('position_request.created', 'position_request', $request->id, null, $this->auditable($request));

            return $request;
        });
    }

    /**
     * Solo en borrador.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(PositionRequest $request, array $input, ?int $expectedVersion): PositionRequest
    {
        if ($expectedVersion !== null && $expectedVersion !== $request->lock_version) {
            throw new VersionConflictException($expectedVersion, $request->lock_version);
        }
        if ($request->status !== 'draft') {
            throw new AyniException('invalid_transition', 409, (string) trans('organization.position_requests.only_draft'), [],
                ['current_status' => $request->status, 'allowed_actions' => $this->machine->manualActions($request->status)]);
        }
        $values = $this->columns($input);
        $this->check($values, $request);

        return DB::transaction(function () use ($request, $values): PositionRequest {
            $before = $this->auditable($request);
            $request->fill($values);
            if ($request->isDirty()) {
                $request->save();
            }
            $request->refresh();
            $this->audit->record('position_request.updated', 'position_request', $request->id, $before, $this->auditable($request));

            return $request;
        });
    }

    /**
     * Enviar a aprobación: valida la estructura vigente, marca si supera el presupuesto (RN-POS-05) e
     * inicia el flujo de `position_request` con el contexto para sus condiciones.
     */
    public function submit(PositionRequest $request, string $userId): PositionRequest
    {
        $this->machine->getNextState($request->status, 'submit');
        $this->check([], $request, true);
        if (trim((string) $request->justification) === '') {
            throw ValidationException::withMessages(['justification' => [(string) trans('organization.position_requests.justification_required')]]);
        }
        $day = $this->effectiveDay($request);
        $cost = $request->estimated_cost !== null && $request->currency !== null ? ['amount' => $request->estimated_cost, 'currency' => $request->currency] : null;
        $overBudget = $this->headcount->exceeds($request->org_unit_id, $day, $request->quantity, $cost);

        return DB::transaction(function () use ($request, $userId, $overBudget, $cost): PositionRequest {
            $locked = PositionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $next = $this->machine->getNextState($from, 'submit');
            $before = $this->auditable($locked);
            $total = $cost === null ? null : (string) BigDecimal::of($cost['amount'])->multipliedBy($locked->quantity)->toScale(2);
            $locked->forceFill(['status' => $next, 'over_budget' => $overBudget, 'submitted_at' => now()])->save();
            $approvalId = $this->approvals->start(self::SUBJECT_TYPE, $locked->id, $locked->requested_by_user_id, [
                'legal_entity_id' => $locked->legal_entity_id,
                'org_unit_id' => $locked->org_unit_id,
                'job_profile_id' => $locked->job_profile_id,
                'quantity' => $locked->quantity,
                'kind' => $locked->kind,
                'over_budget' => $overBudget,
                'amount' => $total,
                'currency' => $cost['currency'] ?? null,
                'submitted_by_user_id' => $userId,
            ]);
            // El motor pudo decidir en el acto (flujo sin aprobadores): no se pisa ese resultado.
            $locked->refresh();
            $locked->forceFill(['workflow_instance_id' => $approvalId])->save();
            $locked->refresh();
            $this->event('submit', $locked, ['over_budget' => $overBudget, 'approval_request_id' => $approvalId]);
            $this->audit->record('position_request.submitted', 'position_request', $locked->id, $before, $this->auditable($locked));

            return $locked;
        });
    }

    public function cancel(PositionRequest $request, ?string $reason): PositionRequest
    {
        $this->machine->getNextState($request->status, 'cancel');

        return DB::transaction(function () use ($request, $reason): PositionRequest {
            $locked = PositionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->machine->getNextState($locked->status, 'cancel');
            if ($locked->workflow_instance_id !== null) {
                // El motor anula los pasos pendientes y avisa al módulo (cancelled): queda idempotente.
                $this->approvals->cancel($locked->workflow_instance_id, $reason);
                $locked->refresh();
            }
            if ($locked->status !== 'cancelled') {
                $this->applyDecision($locked, 'cancel', $reason);
            }

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ Resultado del flujo (WFL-4)

    /** El flujo aprobó: crea las posiciones en «aprobada» con cargo al presupuesto. Idempotente. */
    public function approvedByFlow(string $requestId): void
    {
        $request = PositionRequest::query()->whereKey($requestId)->lockForUpdate()->first();
        if (! $request instanceof PositionRequest || $request->status !== 'submitted') {
            return;
        }
        $this->check([], $request, true);
        $reason = (string) trans($request->over_budget ? 'organization.position_requests.approved_over_budget' : 'organization.position_requests.approved_reason');
        for ($n = 1; $n <= $request->quantity; $n++) {
            $this->positions->createApproved([
                'code' => $this->code($request, $n),
                'title' => $request->title ?? (string) DB::table('job_profiles')->where('id', $request->job_profile_id)->value('name'),
                'job_profile_id' => $request->job_profile_id,
                'legal_entity_id' => (string) $request->legal_entity_id,
                'org_unit_id' => $request->org_unit_id,
                'site_id' => $request->site_id,
                'cost_center_id' => $request->cost_center_id,
                'reports_to_position_id' => $request->reports_to_position_id,
                'salary_band_id' => $request->salary_band_id,
                'valid_from' => $this->effectiveDay($request),
                'budgeted_cost' => $request->estimated_cost,
                'currency' => $request->estimated_cost === null ? null : $request->currency,
                'position_request_id' => $request->id,
            ], $reason);
        }
        $this->applyDecision($request, 'approve', null);
    }

    public function rejectedByFlow(string $requestId, ?string $reason): void
    {
        $request = PositionRequest::query()->whereKey($requestId)->lockForUpdate()->first();
        if ($request instanceof PositionRequest && $request->status === 'submitted') {
            $this->applyDecision($request, 'reject', $reason);
        }
    }

    public function cancelledByFlow(string $requestId, ?string $reason): void
    {
        $request = PositionRequest::query()->whereKey($requestId)->lockForUpdate()->first();
        if ($request instanceof PositionRequest && $this->machine->can($request->status, 'cancel')) {
            $this->applyDecision($request, 'cancel', $reason);
        }
    }

    // ------------------------------------------------------------------ Reglas

    /**
     * Estructura y forma. En una edición solo se valida lo que cambia; al enviar ($full) se valida todo
     * contra la estructura vigente.
     *
     * @param  array<string, mixed>  $v  columnas
     */
    private function check(array $v, ?PositionRequest $request, bool $full = false): void
    {
        $get = fn (string $c) => array_key_exists($c, $v) ? $v[$c] : $request?->getAttribute($c);
        $changed = fn (string $c) => $full || (array_key_exists($c, $v) && (! $request instanceof PositionRequest || $request->getAttribute($c) !== $v[$c]));
        $errors = [];
        $collect = function (callable $assert) use (&$errors): void {
            try {
                $assert();
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->errors());
            }
        };
        $entity = $get('legal_entity_id');
        $unit = $get('org_unit_id');
        if ($changed('legal_entity_id') && is_string($entity)) {
            $collect(fn () => $this->structure->assertLegalEntityAssignable($entity));
        }
        if ($changed('job_profile_id') && is_string($get('job_profile_id'))) {
            $collect(fn () => $this->structure->assertJobProfileAssignable((string) $get('job_profile_id')));
        }
        if ($changed('org_unit_id') && is_string($unit)) {
            $collect(fn () => $this->structure->assertOrgUnitAssignable($unit));
        }
        if (($changed('org_unit_id') || $changed('legal_entity_id')) && is_string($unit) && is_string($entity)) {
            $unitEntity = DB::table('org_units')->where('id', $unit)->value('legal_entity_id');
            if ($unitEntity !== null && $unitEntity !== $entity) {
                $errors['org_unit_id'] = [(string) trans('organization.positions.unit_other_company')];
            }
        }
        if (! is_string($entity) || $entity === '') {
            $errors['legal_entity_id'] = [(string) trans('organization.position_requests.entity_required')];
        }
        $site = $get('site_id');
        if ($changed('site_id') && is_string($site)) {
            $collect(fn () => $this->structure->assertSiteAssignable($site, is_string($entity) ? $entity : null));
        }
        $center = $get('cost_center_id');
        if ($changed('cost_center_id') && is_string($center)) {
            $collect(fn () => $this->structure->assertCostCenterAssignable($center, EffectiveDated::day(now()), is_string($entity) ? $entity : null));
        }
        $boss = $get('reports_to_position_id');
        if ($changed('reports_to_position_id') && is_string($boss)
            && ! Position::query()->whereKey($boss)->where('status', '<>', PositionService::CLOSED)->exists()) {
            $errors['reports_to_position_id'] = [(string) trans('organization.positions.unknown_boss')];
        }
        $band = $get('salary_band_id');
        if ($changed('salary_band_id') && is_string($band) && ! DB::table('salary_bands')->where('id', $band)->whereNull('deleted_at')->exists()) {
            $errors['salary_band_id'] = [(string) trans('organization.positions.unknown_band')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function applyDecision(PositionRequest $request, string $action, ?string $reason): void
    {
        $from = $request->status;
        $next = $this->machine->getNextState($from, $action);
        $before = $this->auditable($request);
        $values = ['status' => $next];
        if (in_array($action, ['approve', 'reject'], true)) {
            $values['decided_at'] = now();
        }
        if ($action !== 'approve') {
            $values['decision_reason'] = $reason;
        }
        $request->forceFill($values)->save();
        $request->refresh();
        $this->event($action, $request, ['reason' => $reason]);
        $this->audit->record('position_request.'.($action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'cancelled')),
            'position_request', $request->id, $before, $this->auditable($request), $reason);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(string $action, PositionRequest $request, array $extra): void
    {
        $this->outbox->record(self::EVENTS[$action], 'position_request', $request->id, [
            'position_request_id' => $request->id, 'org_unit_id' => $request->org_unit_id, 'job_profile_id' => $request->job_profile_id,
            'quantity' => $request->quantity, 'status' => $request->status, 'requested_by_user_id' => $request->requested_by_user_id,
        ] + $extra);
    }

    /** Código único de la n-ésima posición que crea la solicitud. */
    private function code(PositionRequest $request, int $n): string
    {
        $base = 'SP'.strtoupper(substr(str_replace('-', '', $request->id), -6)).'-'.$n;
        $code = $base;
        $i = 1;
        while (Position::query()->withTrashed()->whereRaw('lower(code) = lower(?)', [$code])->exists()) {
            $code = $base.'-'.(++$i);
        }

        return $code;
    }

    /** Fecha desde la que rige la plaza: la fecha requerida si es futura; si no, hoy. */
    private function effectiveDay(PositionRequest $request): string
    {
        $today = EffectiveDated::day(now());
        $needed = $request->needed_by?->toDateString();

        return $needed !== null && $needed > $today ? $needed : $today;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $values = [];
        foreach (self::FIELDS as $in => $column) {
            if (array_key_exists($in, $input)) {
                $values[$column] = $input[$in];
            }
        }
        if (isset($values['title']) && is_string($values['title'])) {
            $values['title'] = trim($values['title']);
        }
        if (array_key_exists('estimated_cost', $input)) {
            $cost = $input['estimated_cost'];
            $values['estimated_cost'] = is_array($cost) ? (string) $cost['amount'] : null;
            $values['currency'] = is_array($cost) ? strtoupper((string) $cost['currency']) : null;
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(PositionRequest $request): array
    {
        return [
            'status' => $request->status, 'legal_entity_id' => $request->legal_entity_id, 'org_unit_id' => $request->org_unit_id,
            'job_profile_id' => $request->job_profile_id, 'title' => $request->title, 'quantity' => $request->quantity,
            'kind' => $request->kind, 'needed_by' => $request->needed_by?->toDateString(), 'justification' => $request->justification,
            'estimated_cost' => $request->estimated_cost, 'currency' => $request->currency, 'over_budget' => $request->over_budget,
            'workflow_instance_id' => $request->workflow_instance_id, 'decision_reason' => $request->decision_reason,
        ];
    }
}
