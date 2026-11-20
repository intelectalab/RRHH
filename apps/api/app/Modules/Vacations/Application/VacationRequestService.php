<?php

namespace App\Modules\Vacations\Application;

use App\Modules\Vacations\Domain\VacationRequestStateMachine;
use App\Modules\Vacations\Infrastructure\Models\VacationAgreement;
use App\Modules\Vacations\Infrastructure\Models\VacationRequest;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Time\VacationBalances;
use App\Shared\Time\WorkingDays;
use App\Shared\Workflow\ApprovalRequests;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * VAC-3 · Solicitud y aprobación de vacaciones (ME-15).
 *
 * 1) El colaborador (o su jefe o RRHH a su nombre) registra el borrador con las fechas; el servidor cuenta
 *    los días según la política (calendario o hábil, con los feriados de la sede, RN-VAC-07).
 * 2) Al enviarlo se valida: saldo disponible más adelanto permitido (RN-VAC-04), anticipación y
 *    fraccionamiento mínimo (RN-VAC-05), solapes con otras vacaciones o ausencias (RN-VAC-06) y periodo
 *    de planilla cerrado (RN-VAC-08). Arranca el flujo `vacation_request` con el contexto de la política
 *    (validación de RRHH, adelanto, cobertura del equipo) para que sus condiciones elijan los niveles.
 * 3) El motor devuelve la decisión por VacationRequestOutcome en la misma transacción. Al aprobar, los días
 *    pasan de «disponibles» a «programadas» en el libro (RN-VAC-03); si el saldo ya no alcanza, la
 *    aprobación no se registra.
 * 4) Cancelar una solicitud aprobada (antes del goce) devuelve los días al saldo. El día de inicio el
 *    sistema la pone «en goce» y la reserva pasa a «tomadas»; al terminar queda «gozada».
 *
 * RRHH (time.vacation_requests.validate) puede registrarla directamente aprobada, con motivo de excepción
 * si no cumple la anticipación o el fraccionamiento.
 */
final class VacationRequestService
{
    public const SUBJECT_TYPE = VacationRequest::SUBJECT;

    /** Estados de la relación laboral que admiten vacaciones. */
    private const EMPLOYMENT_STATES = ['active', 'suspended', 'terminating'];

    /** Estados de ausencia (AUS-2) que ocupan el calendario del trabajador. */
    private const ABSENCE_ACTIVE = ['pending', 'pending_document', 'approved', 'in_progress', 'completed'];

    public function __construct(
        private readonly VacationRequestStateMachine $machine,
        private readonly VacationBalances $balances,
        private readonly WorkingDays $workingDays,
        private readonly ApprovalRequests $approvals,
        private readonly ParameterResolver $parameters,
        private readonly TeamCalendar $team,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ------------------------------------------------------------------ Borrador

    /**
     * @param  array{employment_id: string, starts_on: string, ends_on: string, notes?: string|null, exception_reason?: string|null}  $input
     */
    public function create(array $input, string $userId): VacationRequest
    {
        $values = $this->shape($input['employment_id'], $input['starts_on'], $input['ends_on']);

        return DB::transaction(function () use ($input, $values, $userId): VacationRequest {
            $request = new VacationRequest([
                'employment_id' => $input['employment_id'],
                'starts_on' => $values['starts_on'],
                'ends_on' => $values['ends_on'],
                'days' => $values['days'],
                'calendar_days' => $values['calendar_days'],
                'notes' => self::text($input['notes'] ?? null),
                'exception_reason' => self::text($input['exception_reason'] ?? null),
                'requested_by_user_id' => $userId,
                'status' => 'draft',
                'kind' => 'leave',
            ]);
            $request->save();
            $this->audit->record('vacation_request.created', self::SUBJECT_TYPE, $request->id, null, $this->auditable($request));

            return $request->refresh();
        });
    }

    /**
     * Editar el borrador (fechas, nota, motivo de excepción). Fuera del borrador, 409.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(VacationRequest $request, array $input): VacationRequest
    {
        if ($request->status !== 'draft') {
            throw new AyniException('invalid_transition', 409, (string) trans('vacations.requests.only_draft'), [], ['current_status' => $request->status, 'allowed_actions' => []]);
        }
        $values = $this->shape(
            $request->employment_id,
            is_string($input['starts_on'] ?? null) ? $input['starts_on'] : $request->day('starts_on'),
            is_string($input['ends_on'] ?? null) ? $input['ends_on'] : $request->day('ends_on'),
        );

        return DB::transaction(function () use ($request, $input, $values): VacationRequest {
            $before = $this->auditable($request);
            $request->fill(['starts_on' => $values['starts_on'], 'ends_on' => $values['ends_on'], 'days' => $values['days'], 'calendar_days' => $values['calendar_days']]);
            foreach (['notes', 'exception_reason'] as $field) {
                if (array_key_exists($field, $input)) {
                    $request->setAttribute($field, self::text(is_string($input[$field]) ? $input[$field] : null));
                }
            }
            $request->lock_version++;
            $request->save();
            $this->audit->record('vacation_request.updated', self::SUBJECT_TYPE, $request->id, $before, $this->auditable($request));

            return $request->refresh();
        });
    }

    // ------------------------------------------------------------------ Validación (simulación incluida)

    /**
     * Cuenta los días y revisa todas las reglas sin escribir nada. Lo usan la simulación de la pantalla y,
     * lanzando el primer problema, el envío y el registro directo.
     *
     * @return array{days: string, calendar_days: int, working_days: int, counts_calendar_days: bool, holidays: list<string>, balance: array{available: string, advance_available: string, pending_validation: bool}, advance_days: string, coverage: list<array<string, mixed>>, issues: list<array{code: string, status: int, message: string}>, warnings: list<array{code: string, message: string}>}
     */
    public function evaluate(string $employmentId, string $startsOn, string $endsOn, string $role, ?string $exceptionReason = null, ?string $ignoreId = null): array
    {
        $issues = [];
        $warnings = [];
        $issue = function (string $code, int $status, string $message) use (&$issues): void {
            $issues[] = ['code' => $code, 'status' => $status, 'message' => $message];
        };

        $values = $this->shape($employmentId, $startsOn, $endsOn);
        $employment = $this->employment($employmentId);
        $policy = $this->balances->policyFor($employmentId, $startsOn);
        /** @var array<string, mixed> $rules */
        $rules = is_array($policy['rules'] ?? null) ? $policy['rules'] : [];
        /** @var array<string, array<string, mixed>> $legal */
        $legal = is_array($policy['legal'] ?? null) ? $policy['legal'] : [];
        $days = BigDecimal::of($values['days']);
        $exception = self::text($exceptionReason);

        if (! in_array((string) $employment->status, self::EMPLOYMENT_STATES, true)) {
            $issue('employment_not_active', 422, (string) trans('vacations.requests.employment_not_active'));
        }
        $hire = substr((string) $employment->hire_date, 0, 10);
        $end = $employment->termination_date === null ? null : substr((string) $employment->termination_date, 0, 10);
        if ($startsOn < $hire || ($end !== null && $endsOn > $end)) {
            $issue('outside_employment', 422, (string) trans('vacations.requests.outside_employment'));
        }
        if (! $days->isPositive()) {
            $issue('no_days', 422, (string) trans('vacations.requests.no_days'));
        }

        // RN-VAC-05 · Anticipación mínima (parámetro de la organización o empresa). RRHH registra con motivo.
        $notice = $this->parameters->value('vacaciones.anticipacion_minima_dias', $employment->legal_entity_id);
        $today = now()->toDateString();
        if (is_numeric($notice) && (int) $notice > 0) {
            $ahead = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($startsOn), false);
            if ($ahead < (int) $notice) {
                $this->exceptionOrIssue($role, $exception, 'insufficient_notice', (string) trans('vacations.requests.insufficient_notice', ['days' => (int) $notice]), $issue, $warnings);
            }
        }

        // RN-VAC-05 · Fraccionamiento mínimo: el de la política se exige; el legal por validar solo avisa.
        $ownMin = $rules['min_days_per_request'] ?? null;
        $legalMin = $legal['min_fraction_days'] ?? null;
        // VAC-6 · Con un acuerdo de fraccionamiento firmado y vigente, el mínimo de la política no se exige: rige el
        // bloque mínimo legal (o solo se avisa mientras está por validar).
        if (VacationAgreement::query()->where('employment_id', $employmentId)->where('kind', 'fractionation')->where('status', 'active')
            ->where('effective_on', '<=', $startsOn)->exists()) {
            $ownMin = null;
        }
        if (is_string($ownMin) || is_numeric($ownMin)) {
            if ($days->isPositive() && $days->isLessThan((string) $ownMin)) {
                $this->exceptionOrIssue($role, $exception, 'below_min_fraction', (string) trans('vacations.requests.below_min_fraction', ['days' => self::number((string) $ownMin)]), $issue, $warnings);
            }
        } elseif (is_array($legalMin) && $legalMin['value'] !== null && $days->isPositive() && $days->isLessThan((string) $legalMin['value'])) {
            if (($legalMin['verified'] ?? false) === true && ($legalMin['pending_validation'] ?? false) !== true) {
                $this->exceptionOrIssue($role, $exception, 'below_min_fraction', (string) trans('vacations.requests.below_min_fraction', ['days' => self::number((string) $legalMin['value'])]), $issue, $warnings);
            } else {
                $warnings[] = ['code' => 'min_fraction_pending', 'message' => (string) trans('vacations.requests.min_fraction_pending', ['days' => self::number((string) $legalMin['value'])])];
            }
        }

        // RN-VAC-04 · Saldo disponible más adelanto permitido (lo calcula el libro; el navegador no suma días).
        $balance = $this->balances->balance($employmentId);
        $available = BigDecimal::of((string) $balance['totals']['available']);
        $advanceAvailable = BigDecimal::of((string) $balance['advance_available']);
        // Solo se evalúan borradores y simulaciones: los días de otras solicitudes en trámite aún no están
        // reservados en el libro, así que se restan aquí para no prometer dos veces el mismo saldo.
        $pendingOthers = $this->pendingDays($employmentId, $ignoreId);
        $free = $available->minus($pendingOthers);
        $advanceDays = BigDecimal::zero();
        if ($days->isGreaterThan($free)) {
            $advanceDays = $days->minus(BigDecimal::max($free, BigDecimal::zero()));
            if ($advanceDays->isGreaterThan($advanceAvailable)) {
                $issue('insufficient_vacation_balance', 422, (string) trans('vacations.insufficient_balance', [
                    'requested' => self::number((string) $days), 'available' => self::number((string) BigDecimal::max($free, BigDecimal::zero())), 'advance' => self::number((string) $advanceAvailable),
                ]));
            } else {
                $warnings[] = ['code' => 'uses_advance', 'message' => (string) trans('vacations.requests.uses_advance', ['days' => self::number((string) $advanceDays)])];
            }
        }
        if ($pendingOthers->isPositive()) {
            $warnings[] = ['code' => 'pending_requests', 'message' => (string) trans('vacations.requests.pending_requests', ['days' => self::number((string) $pendingOthers)])];
        }
        if ($balance['pending_validation']) {
            $warnings[] = ['code' => 'legal_days_pending', 'message' => (string) trans('vacations.legal_days_pending', ['parameter' => 'vacaciones_dias'])];
        }

        // RN-VAC-06 · Sin solapes con otras vacaciones ni con ausencias del mismo trabajador.
        $overlap = $this->overlapping($employmentId, $startsOn, $endsOn, $ignoreId);
        if ($overlap !== null) {
            $issue($overlap['code'], 409, (string) trans('vacations.requests.'.$overlap['code'], ['from' => $overlap['starts_on'], 'to' => $overlap['ends_on']]));
        }

        // RN-VAC-08 · Periodo de planilla cerrado: solo RRHH, y su efecto va como ajuste al periodo abierto.
        $closed = $this->closedUntil($employmentId, $employment->legal_entity_id);
        if ($closed !== null && $startsOn <= $closed) {
            if ($role === 'hr') {
                $warnings[] = ['code' => 'closed_period_adjustment', 'message' => (string) trans('vacations.requests.closed_period_adjustment', ['date' => $closed])];
            } else {
                $issue('closed_period', 422, (string) trans('vacations.requests.closed_period', ['date' => $closed]));
            }
        }

        // VAC-4 · Cobertura del equipo: más ausentes simultáneos que el máximo de la unidad (solo aviso).
        $coverage = $this->team->coverageFor($employmentId, $startsOn, $endsOn, $ignoreId);
        if ($coverage !== []) {
            $warnings[] = ['code' => 'coverage_exceeded', 'message' => (string) trans('vacations.requests.coverage_exceeded', [
                'from' => $coverage[0]['from'], 'to' => $coverage[array_key_last($coverage)]['to'], 'limit' => $coverage[0]['limit'],
            ])];
        }

        return [
            'days' => $values['days'],
            'calendar_days' => (int) $values['calendar_days'],
            'working_days' => $values['working_days'],
            'counts_calendar_days' => $values['counts_calendar_days'],
            'holidays' => $values['holidays'],
            'balance' => [
                'available' => self::number((string) BigDecimal::max($free, BigDecimal::zero())),
                'advance_available' => self::number((string) $advanceAvailable),
                'pending_validation' => $balance['pending_validation'],
            ],
            'advance_days' => self::number((string) $advanceDays),
            'coverage' => $coverage,
            'issues' => $issues,
            'warnings' => $warnings,
        ];
    }

    // ------------------------------------------------------------------ Acciones

    /**
     * Enviar el borrador: valida y arranca el flujo de aprobación.
     *
     * @return array{request: VacationRequest, warnings: list<array{code: string, message: string}>}
     */
    public function submit(VacationRequest $request, string $role, string $userId): array
    {
        $this->machine->getNextState($request->status, 'submit');
        $check = $this->check($request, $role);

        $result = DB::transaction(function () use ($request, $check, $userId): VacationRequest {
            $locked = $this->lock($request, $check);
            $this->machine->getNextState($locked->status, 'submit');
            $before = $this->auditable($locked);
            $locked->forceFill(['submitted_at' => now(), 'status' => $this->machine->getNextState($locked->status, 'submit')]);
            $this->save($locked);
            $employment = $this->employment($locked->employment_id);
            $policy = $this->balances->policyFor($locked->employment_id, $locked->day('starts_on'));
            /** @var array<string, mixed> $rules */
            $rules = is_array($policy['rules'] ?? null) ? $policy['rules'] : [];
            $hrValidation = ($rules['requires_hr_validation'] ?? false) === true
                || $this->parameters->value('vacaciones.valida_rrhh', $employment->legal_entity_id) === true;
            $approvalId = $this->approvals->start(self::SUBJECT_TYPE, $locked->id, $this->ownerUserId($locked->employment_id) ?? $userId, [
                'legal_entity_id' => $employment->legal_entity_id,
                'employment_id' => $locked->employment_id,
                'days' => $locked->days,
                'advance_days' => $locked->advance_days,
                'uses_advance' => $locked->kind === 'advance',
                'starts_on' => $locked->day('starts_on'),
                'ends_on' => $locked->day('ends_on'),
                'requires_hr_validation' => $hrValidation,
                'coverage_exceeded' => $check['coverage'] !== [],
                'workflow_definition_id' => $rules['approval_workflow_definition_id'] ?? null,
                'submitted_by_user_id' => $userId,
            ]);
            // El motor pudo decidir en el acto (flujo sin aprobadores): no se pisa ese resultado.
            $locked->refresh();
            $locked->forceFill(['workflow_instance_id' => $approvalId]);
            $this->save($locked);
            $this->event(DomainEventTypes::LEAVE_REQUESTED, $locked, ['approval_request_id' => $approvalId]);
            $this->audit->record('vacation_request.submitted', self::SUBJECT_TYPE, $locked->id, $before, $this->auditable($locked));

            return $locked->refresh();
        });

        return ['request' => $result, 'warnings' => $check['warnings']];
    }

    /**
     * RRHH registra la solicitud ya aprobada, sin flujo (por ejemplo, vacaciones acordadas fuera del sistema).
     *
     * @return array{request: VacationRequest, warnings: list<array{code: string, message: string}>}
     */
    public function register(VacationRequest $request, string $userId): array
    {
        $this->machine->getNextState($request->status, 'register');
        $check = $this->check($request, 'hr');

        $result = DB::transaction(function () use ($request, $check, $userId): VacationRequest {
            $locked = $this->lock($request, $check);
            $this->machine->getNextState($locked->status, 'register');
            $before = $this->auditable($locked);
            $locked->forceFill(['submitted_at' => now()]);
            $this->approve($locked, 'register', $userId);
            $this->audit->record('vacation_request.registered', self::SUBJECT_TYPE, $locked->id, $before, $this->auditable($locked));

            return $locked->refresh();
        });

        return ['request' => $result, 'warnings' => $check['warnings']];
    }

    /**
     * Cancelar (titular o RRHH) antes del inicio del goce. En trámite anula los pasos del flujo; aprobada
     * devuelve los días al saldo (VAC-3).
     */
    public function cancel(VacationRequest $request, ?string $reason, string $userId): VacationRequest
    {
        $this->assertCancellable($request);
        if ($request->status !== 'draft' && self::text($reason) === null) {
            throw ValidationException::withMessages(['reason' => [(string) trans('vacations.requests.reason_required')]]);
        }
        if ($request->status === 'approved') {
            $this->assertOpenPeriod($request);
        }

        return DB::transaction(function () use ($request, $reason, $userId): VacationRequest {
            $locked = VacationRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertCancellable($locked);
            if ($locked->workflow_instance_id !== null && in_array($locked->status, VacationRequestStateMachine::PENDING_STATES, true)) {
                // El motor anula los pasos pendientes y avisa al módulo (cancelled): queda idempotente.
                $this->approvals->cancel($locked->workflow_instance_id, $reason);
                $locked->refresh();
            }
            if ($locked->status !== 'cancelled') {
                $this->applyCancel($locked, $reason, $userId);
            }

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ Resultado del flujo (WFL-4)

    public function approvedByFlow(string $id, ?string $decidedBy = null): void
    {
        $request = VacationRequest::query()->whereKey($id)->lockForUpdate()->first();
        if (! $request instanceof VacationRequest || ! in_array($request->status, VacationRequestStateMachine::PENDING_STATES, true)) {
            return;
        }
        $before = $this->auditable($request);
        $this->approve($request, 'approve', $decidedBy);
        $this->audit->record('vacation_request.approved', self::SUBJECT_TYPE, $request->id, $before, $this->auditable($request));
    }

    public function rejectedByFlow(string $id, ?string $reason, ?string $decidedBy = null): void
    {
        $request = VacationRequest::query()->whereKey($id)->lockForUpdate()->first();
        if (! $request instanceof VacationRequest || ! in_array($request->status, VacationRequestStateMachine::PENDING_STATES, true)) {
            return;
        }
        $before = $this->auditable($request);
        $this->transition($request, 'reject', ['decided_at' => now(), 'decision_reason' => self::text($reason)]);
        $this->event(DomainEventTypes::LEAVE_REJECTED, $request, ['rejected_by' => $decidedBy, 'reason' => self::text($reason) ?? '']);
        $this->audit->record('vacation_request.rejected', self::SUBJECT_TYPE, $request->id, $before, $this->auditable($request), $reason);
    }

    public function cancelledByFlow(string $id, ?string $reason): void
    {
        $request = VacationRequest::query()->whereKey($id)->lockForUpdate()->first();
        if ($request instanceof VacationRequest && $this->machine->can($request->status, 'cancel')) {
            $this->applyCancel($request, $reason, null);
        }
    }

    // ------------------------------------------------------------------ Tarea diaria

    /**
     * El día de inicio pasa la solicitud a «en goce» y la reserva a «tomadas»; al terminar queda «gozada».
     * Idempotente.
     *
     * @return array{started: int, finished: int}
     */
    public function advance(?string $today = null): array
    {
        $today ??= now()->toDateString();
        $counts = ['started' => 0, 'finished' => 0];
        $ids = VacationRequest::query()->where(function ($q) use ($today): void {
            $q->where(fn ($w) => $w->where('status', 'approved')->where('starts_on', '<=', $today))
                ->orWhere(fn ($w) => $w->where('status', 'in_progress')->where('ends_on', '<', $today));
        })->pluck('id')->all();

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $today, &$counts): void {
                $request = VacationRequest::query()->whereKey($id)->lockForUpdate()->first();
                if (! $request instanceof VacationRequest) {
                    return;
                }
                $result = $this->advanceOne($request, $today);
                $counts['started'] += $result['started'];
                $counts['finished'] += $result['finished'];
            });
        }

        return $counts;
    }

    // ------------------------------------------------------------------ Presentación

    /**
     * @return array<string, mixed>
     */
    public function present(VacationRequest $r, bool $detail = false): array
    {
        $employment = DB::table('employments as e')->leftJoin('people as p', 'p.id', '=', 'e.person_id')
            ->where('e.id', $r->employment_id)->first(['e.employee_code', 'e.person_id', 'e.legal_entity_id', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname']);

        $row = [
            'id' => $r->id,
            'employment_id' => $r->employment_id,
            'person_id' => $employment?->person_id,
            'person_name' => $employment === null ? null : trim($employment->first_names.' '.$employment->paternal_surname.' '.($employment->maternal_surname ?? '')),
            'employee_code' => $employment?->employee_code,
            'legal_entity_id' => $employment?->legal_entity_id,
            'starts_on' => $r->day('starts_on'),
            'ends_on' => $r->day('ends_on'),
            'days' => self::number((string) $r->days),
            'calendar_days' => $r->calendar_days === null ? null : self::number((string) $r->calendar_days),
            'advance_days' => $r->advance_days === null ? null : self::number((string) $r->advance_days),
            'advance' => $r->kind === 'advance',
            'kind' => $r->kind,
            'status' => $r->status,
            'notes' => $r->notes,
            'exception_reason' => $r->exception_reason,
            'requested_by_user_id' => $r->requested_by_user_id,
            'requested_by_name' => $r->requested_by_user_id === null ? null : DB::table('users')->where('id', $r->requested_by_user_id)->value('display_name'),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_reason' => $r->decision_reason,
            'cancelled_at' => $r->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $r->cancellation_reason,
            'started_at' => $r->started_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'approval_request_id' => $r->workflow_instance_id,
            'version' => $r->lock_version,
            'created_at' => $r->created_at->toIso8601String(),
            'updated_at' => $r->updated_at->toIso8601String(),
        ];
        if ($detail && $r->workflow_instance_id !== null && in_array($r->status, VacationRequestStateMachine::PENDING_STATES, true)) {
            $row['approval'] = $this->approvals->summary($r->workflow_instance_id);
        }

        return $row;
    }

    /**
     * Acciones que el usuario puede pedir (la interfaz solo muestra; el servidor valida).
     *
     * @param  callable(string): bool  $can
     * @return list<string>
     */
    public function allowedActions(VacationRequest $r, callable $can, bool $isOwner): array
    {
        $hr = $can('time.vacation_requests.validate');
        $out = [];
        foreach ($this->machine->manualActions($r->status) as $action) {
            $ok = match ($action) {
                'submit' => $can('time.vacation_requests.submit'),
                'register' => $hr,
                'cancel' => ($isOwner || $hr) && $can('time.vacation_requests.cancel') && $this->cancellable($r),
                default => false,
            };
            if ($ok) {
                $out[] = $action;
            }
        }
        if ($r->status === 'draft' && $can('time.vacation_requests.update')) {
            $out[] = 'update';
        }

        return $out;
    }

    // ------------------------------------------------------------------ Internos

    /**
     * Valida forma y cuenta los días con el cómputo de la política vigente al inicio (RN-VAC-07).
     *
     * @return array{starts_on: string, ends_on: string, days: string, calendar_days: string, working_days: int, counts_calendar_days: bool, holidays: list<string>}
     */
    private function shape(string $employmentId, string $startsOn, string $endsOn): array
    {
        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages(['ends_on' => [(string) trans('vacations.requests.ends_before_start')]]);
        }
        if ((int) CarbonImmutable::parse($startsOn)->diffInDays(CarbonImmutable::parse($endsOn)) > 365) {
            throw ValidationException::withMessages(['ends_on' => [(string) trans('vacations.requests.too_long')]]);
        }
        $employment = $this->employment($employmentId);
        $policy = $this->balances->policyFor($employmentId, $startsOn);
        $calendar = (($policy['rules']['counts_calendar_days'] ?? true) !== false);
        $count = $this->workingDays->count($employment->legal_entity_id, $this->siteAt($employmentId, $startsOn), $startsOn, $endsOn);

        return [
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'days' => (string) ($calendar ? $count['calendar_days'] : $count['working_days']),
            'calendar_days' => (string) $count['calendar_days'],
            'working_days' => $count['working_days'],
            'counts_calendar_days' => $calendar,
            'holidays' => $count['holidays'],
        ];
    }

    /**
     * @return array{warnings: list<array{code: string, message: string}>, coverage: list<array<string, mixed>>, days: string, calendar_days: string, advance_days: string}
     */
    private function check(VacationRequest $request, string $role): array
    {
        $result = $this->evaluate($request->employment_id, $request->day('starts_on'), $request->day('ends_on'), $role, $request->exception_reason, $request->id);
        if ($result['issues'] !== []) {
            $first = $result['issues'][0];
            throw new AyniException($first['code'], $first['status'], $first['message'], [], ['issues' => $result['issues']]);
        }

        return [
            'warnings' => $result['warnings'],
            'coverage' => $result['coverage'],
            'days' => $result['days'],
            'calendar_days' => (string) $result['calendar_days'],
            'advance_days' => $result['advance_days'],
        ];
    }

    /**
     * Bloquea la fila y conserva el cómputo vigente al enviar (los feriados pudieron cambiar desde el borrador).
     *
     * @param  array{days: string, calendar_days: string, advance_days: string}  $check
     */
    private function lock(VacationRequest $request, array $check): VacationRequest
    {
        /** @var VacationRequest $locked */
        $locked = VacationRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        $advance = BigDecimal::of($check['advance_days']);
        $locked->forceFill([
            'days' => $check['days'], 'calendar_days' => $check['calendar_days'],
            'advance_days' => $advance->isPositive() ? (string) $advance : null,
            'kind' => $advance->isPositive() ? 'advance' : 'leave',
        ]);

        return $locked;
    }

    /**
     * Aprobación final (por el flujo o registro directo de RRHH): reserva los días en el libro (RN-VAC-03).
     * Si el saldo ya no alcanza, lanza el error y la decisión no se registra.
     */
    private function approve(VacationRequest $request, string $action, ?string $decidedBy): void
    {
        // El tope de adelanto es el vigente al decidir (la política o el saldo pudieron cambiar desde el envío).
        $advance = (string) $this->balances->balance($request->employment_id)['advance_available'];
        $this->balances->reserve($request->employment_id, $request->id, (string) $request->days, $request->day('starts_on'), $decidedBy, $advance);
        $this->transition($request, $action, ['decided_at' => now(), 'approved_at' => now()]);
        $this->event(DomainEventTypes::LEAVE_APPROVED, $request, ['approved_by' => $decidedBy, 'registered_directly' => $action === 'register']);
        $this->advanceOne($request, now()->toDateString());
    }

    /**
     * @return array{started: int, finished: int}
     */
    private function advanceOne(VacationRequest $request, string $today): array
    {
        $out = ['started' => 0, 'finished' => 0];
        if ($request->status === 'approved' && $request->day('starts_on') <= $today) {
            $this->balances->consume($request->id, $request->day('starts_on'), null);
            $this->transition($request, 'start', ['started_at' => now()]);
            $this->event(DomainEventTypes::LEAVE_STARTED, $request);
            $out['started']++;
        }
        if ($request->status === 'in_progress' && $request->day('ends_on') < $today) {
            $this->transition($request, 'finish', ['completed_at' => now()]);
            $this->event(DomainEventTypes::LEAVE_COMPLETED, $request);
            $out['finished']++;
        }

        return $out;
    }

    private function applyCancel(VacationRequest $request, ?string $reason, ?string $userId): void
    {
        $before = $this->auditable($request);
        $wasApproved = $request->status === 'approved';
        $this->transition($request, 'cancel', [
            'cancelled_at' => now(), 'cancelled_by_user_id' => $userId, 'cancellation_reason' => self::text($reason),
        ]);
        if ($wasApproved) {
            // Devuelve al saldo los días reservados (contraasiento en el libro).
            $this->balances->release($request->id, self::text($reason) ?? (string) trans('vacations.requests.cancelled_reason'), $userId);
        }
        $this->event(DomainEventTypes::LEAVE_CANCELLED, $request, ['reason' => self::text($reason), 'released' => $wasApproved]);
        $this->audit->record('vacation_request.cancelled', self::SUBJECT_TYPE, $request->id, $before, $this->auditable($request), $reason);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(VacationRequest $request, string $action, array $extra = []): void
    {
        $next = $this->machine->getNextState($request->status, $action);
        $request->forceFill(['status' => $next] + $extra);
        $this->save($request);
    }

    /** Guarda traduciendo el solape (exclusión de la base) a un 409 claro. */
    private function save(VacationRequest $request): void
    {
        $request->lock_version++;
        try {
            DB::transaction(fn () => $request->save());
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw new AyniException('vacation_overlap', 409, (string) trans('vacations.requests.vacation_overlap_generic'));
            }
            throw $e;
        }
    }

    private function cancellable(VacationRequest $r): bool
    {
        if (! $this->machine->can($r->status, 'cancel')) {
            return false;
        }

        // Una solicitud aprobada se cancela solo antes del inicio del goce.
        return $r->status !== 'approved' || $r->day('starts_on') > now()->toDateString();
    }

    private function assertCancellable(VacationRequest $r): void
    {
        $this->machine->getNextState($r->status, 'cancel');
        if (! $this->cancellable($r)) {
            throw new AyniException('invalid_transition', 409, (string) trans('vacations.requests.cancel_started'), [], ['current_status' => $r->status, 'allowed_actions' => []]);
        }
    }

    /** RN-VAC-08 · No se cancela una solicitud aprobada cuyo goce cae en un periodo de planilla cerrado. */
    private function assertOpenPeriod(VacationRequest $r): void
    {
        $employment = $this->employment($r->employment_id);
        $closed = $this->closedUntil($r->employment_id, $employment->legal_entity_id);
        if ($closed !== null && $r->day('starts_on') <= $closed) {
            throw new AyniException('closed_period', 422, (string) trans('vacations.requests.closed_period', ['date' => $closed]));
        }
    }

    /**
     * @param  callable(string, int, string): void  $issue
     * @param  list<array{code: string, message: string}>  $warnings
     */
    private function exceptionOrIssue(string $role, ?string $exception, string $code, string $message, callable $issue, array &$warnings): void
    {
        if ($role === 'hr' && $exception !== null) {
            $warnings[] = ['code' => $code.'_exception', 'message' => $message];
        } else {
            $issue($code, 422, $role === 'hr' ? $message.' '.trans('vacations.requests.hr_exception_hint') : $message);
        }
    }

    /** Días de otras solicitudes en trámite de la misma relación (aún no reservados en el libro). */
    private function pendingDays(string $employmentId, ?string $ignoreId): BigDecimal
    {
        $sum = VacationRequest::query()->where('employment_id', $employmentId)->whereIn('status', VacationRequestStateMachine::PENDING_STATES)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->sum('days');

        return BigDecimal::of((string) ($sum ?: '0'));
    }

    /**
     * @return array{code: string, starts_on: string, ends_on: string}|null
     */
    private function overlapping(string $employmentId, string $startsOn, string $endsOn, ?string $ignoreId): ?array
    {
        $vacation = VacationRequest::query()->where('employment_id', $employmentId)->whereIn('kind', ['leave', 'advance'])
            ->whereIn('status', VacationRequestStateMachine::ACTIVE_STATES)
            ->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))->first();
        if ($vacation instanceof VacationRequest) {
            return ['code' => 'vacation_overlap', 'starts_on' => $vacation->day('starts_on'), 'ends_on' => $vacation->day('ends_on')];
        }
        // Ausencias (AUS-2): la tabla se lee directamente (con RLS); el módulo de ausencias no se importa.
        $absence = DB::table('absence_requests')->where('employment_id', $employmentId)->whereNull('deleted_at')
            ->whereIn('status', self::ABSENCE_ACTIVE)->where('starts_on', '<=', $endsOn)->where('ends_on', '>=', $startsOn)
            ->first(['starts_on', 'ends_on']);

        return $absence === null ? null : ['code' => 'absence_overlap', 'starts_on' => substr((string) $absence->starts_on, 0, 10), 'ends_on' => substr((string) $absence->ends_on, 0, 10)];
    }

    /** Último día de planilla cerrado para la relación (grupo de planilla o, sin grupo, la empresa). */
    private function closedUntil(string $employmentId, string $legalEntityId): ?string
    {
        $groups = DB::table('payroll_group_members')->where('employment_id', $employmentId)->pluck('payroll_group_id')->all();
        $closed = DB::table('payroll_periods as p')->join('payroll_groups as g', 'g.id', '=', 'p.payroll_group_id')
            ->where('p.kind', 'regular')->whereIn('p.status', ['closed', 'paid', 'accounted'])
            ->when($groups !== [], fn ($q) => $q->whereIn('p.payroll_group_id', $groups), fn ($q) => $q->where('g.legal_entity_id', $legalEntityId))
            ->max('p.ends_on');

        return $closed === null ? null : substr((string) $closed, 0, 10);
    }

    /**
     * @return object{status: string, hire_date: string, termination_date: string|null, legal_entity_id: string, person_id: string}
     */
    private function employment(string $id): object
    {
        $row = DB::table('employments')->where('id', $id)->whereNull('deleted_at')->first(['status', 'hire_date', 'termination_date', 'legal_entity_id', 'person_id']);
        if ($row === null) {
            throw ValidationException::withMessages(['employment_id' => [(string) trans('vacations.employment_not_found')]]);
        }

        /** @var object{status: string, hire_date: string, termination_date: string|null, legal_entity_id: string, person_id: string} */
        return $row;
    }

    private function siteAt(string $employmentId, string $asOf): ?string
    {
        $site = DB::table('employment_assignments')->where('employment_id', $employmentId)->where('valid_from', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $asOf))->value('site_id');

        return $site === null ? null : (string) $site;
    }

    private function ownerUserId(string $employmentId): ?string
    {
        $person = DB::table('employments')->where('id', $employmentId)->value('person_id');
        $user = $person === null ? null : DB::table('users')->where('person_id', $person)->whereNull('deleted_at')->value('id');

        return $user === null ? null : (string) $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(string $type, VacationRequest $r, array $extra = []): void
    {
        $this->outbox->record($type, self::SUBJECT_TYPE, $r->id, [
            'leave_id' => $r->id,
            'leave_type' => 'vacation',
            'employee_id' => $r->employment_id,
            'employment_id' => $r->employment_id,
            'start_date' => $r->day('starts_on'),
            'end_date' => $r->day('ends_on'),
            'days' => self::number((string) $r->days),
            'advance_days' => $r->advance_days === null ? null : self::number((string) $r->advance_days),
            'status' => $r->status,
            'tenant_id' => TenancyScope::requireTenant(),
        ] + $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(VacationRequest $r): array
    {
        return [
            'employment_id' => $r->employment_id, 'status' => $r->status, 'kind' => $r->kind,
            'starts_on' => $r->day('starts_on'), 'ends_on' => $r->day('ends_on'), 'days' => $r->days,
            'advance_days' => $r->advance_days, 'exception_reason' => $r->exception_reason,
            'workflow_instance_id' => $r->workflow_instance_id,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
