<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\CompensationChangeStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Compensation;
use App\Modules\CoreHR\Infrastructure\Models\CompensationChange;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use App\Shared\Workflow\ApprovalRequests;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HIS-3 · Compensación con vigencia (C-03, C-11): sueldo base, moneda, periodicidad, base de cálculo y
 * componentes fijos recurrentes de una relación laboral. Es la única fuente del sueldo.
 *
 * - Un cambio se pide con fecha efectiva y motivo (personnel_actions de tipo salary_change). Con
 *   compensacion.cambio_requiere_aprobacion (por defecto) pasa por el flujo de aprobación publicado para
 *   salary_change; si no, se registra en el acto.
 * - Al aplicarse cierra la fila vigente el día anterior e inserta otra (RN-HIS-01); el cambio queda
 *   enlazado a la fila (RN-HIS-08) y auditado con valor anterior y nuevo.
 * - El sueldo mensual no puede quedar bajo la remuneración mínima vigente a la fecha (parámetro legal
 *   rmv del país y régimen, nunca un número en el código); en un contrato a tiempo parcial solo se advierte.
 * - La banda salarial de la posición es referencia: fuera de banda se advierte y el dato viaja al flujo.
 * - Los montos se manejan como decimales exactos (Brick\Math), nunca float.
 */
final class CompensationService
{
    public const SUBJECT_TYPE = 'salary_change';

    public const REASONS = ['merit', 'promotion', 'adjustment', 'legal', 'review_cycle', 'correction'];

    public const FREQUENCIES = ['monthly', 'biweekly', 'weekly', 'daily', 'hourly'];

    public const BASES = ['monthly', 'daily', 'hourly'];

    /** Estados de la relación en los que se puede cambiar la compensación. */
    private const OPEN_STATUSES = ['pending', 'active', 'suspended', 'terminating'];

    public function __construct(
        private readonly EffectiveDated $validity,
        private readonly LegalParameters $legal,
        private readonly ParameterResolver $parameters,
        private readonly ApprovalRequests $approvals,
        private readonly CompensationChangeStateMachine $machine,
        private readonly CompensationConcepts $concepts,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    // ------------------------------------------------------------------ Consulta

    /**
     * Historia de la compensación, de la más reciente a la más antigua; con $asOf, solo la vigente ese día.
     *
     * @return list<Compensation>
     */
    public function history(Employment $employment, ?string $asOf = null): array
    {
        $query = Compensation::query()->where('employment_id', $employment->id);
        if ($asOf !== null) {
            EffectiveDated::applyAsOf($query->getQuery(), $asOf);
        }

        return array_values($query->orderByDesc('valid_from')->get()->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function presentCompensation(Compensation $c): array
    {
        // Componentes vigentes en la fecha de referencia del registro: hoy, acotado a su vigencia.
        $today = now()->toDateString();
        $from = $c->valid_from->toDateString();
        $to = $c->valid_to?->toDateString();
        $ref = $today < $from ? $from : ($to !== null && $today > $to ? $to : $today);

        return [
            'id' => $c->id,
            'employment_id' => $c->employment_id,
            'base_salary' => self::money((string) $c->base_salary, $c->currency),
            'pay_frequency' => $c->pay_frequency,
            'salary_basis' => $c->salary_basis,
            'is_integral_salary' => (bool) $c->is_integral_salary,
            'change_reason' => $c->change_reason,
            'components' => $this->componentsAt($c->employment_id, $ref),
            'valid_from' => $from,
            'valid_to' => $to,
            'personnel_action_id' => $c->personnel_action_id,
        ];
    }

    /**
     * @return list<CompensationChange>
     */
    public function changes(Employment $employment, ?string $status = null): array
    {
        $query = CompensationChange::query()->where('employment_id', $employment->id);
        if ($status !== null) {
            $query->whereIn('status', explode(',', $status));
        }

        return array_values($query->orderByDesc('created_at')->get()->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function presentChange(CompensationChange $change): array
    {
        $p = $change->payload ?? [];
        $row = [
            'id' => $change->id,
            'employment_id' => $change->employment_id,
            'base_salary' => $p['base_salary'] ?? null,
            'pay_frequency' => $p['pay_frequency'] ?? 'monthly',
            'salary_basis' => $p['salary_basis'] ?? 'monthly',
            'is_integral_salary' => (bool) ($p['is_integral_salary'] ?? false),
            'components' => $p['components'] ?? null,
            'effective_on' => $change->effective_on->toDateString(),
            'reason' => $change->reason,
            'notes' => $p['notes'] ?? null,
            'status' => $change->status,
            'band_position' => $p['band_position'] ?? 'none',
            'compensation_id' => $p['compensation_id'] ?? null,
            'approval_request_id' => $p['approval_request_id'] ?? null,
            'decided_at' => $p['decided_at'] ?? null,
            'decision_reason' => $p['decision_reason'] ?? null,
            'requested_by_user_id' => $change->requested_by_user_id,
            'requested_by_name' => $change->requested_by_user_id === null ? null
                : DB::table('users')->where('id', $change->requested_by_user_id)->value('display_name'),
            'allowed_actions' => array_values(array_diff($this->machine->allowedActions($change->status), ['apply', 'reject'])),
            'created_at' => $change->created_at->toIso8601String(),
            'updated_at' => $change->updated_at->toIso8601String(),
        ];
        if (isset($p['previous_salary']) && is_array($p['previous_salary'])) {
            $row['previous_salary'] = $p['previous_salary'];
        }
        if ($row['approval_request_id'] !== null && $change->status === 'pending_approval') {
            $row['approval'] = $this->approvals->summary((string) $row['approval_request_id']);
        }

        return $row;
    }

    // ------------------------------------------------------------------ Registro

    /**
     * Pide un cambio de compensación. Devuelve el cambio (aplicado o pendiente de aprobación) y las
     * advertencias (fuera de banda, mínimo sin verificar, tiempo parcial).
     *
     * @param  array<string, mixed>  $input
     * @return array{change: CompensationChange, warnings: list<array{code: string, message: string}>}
     */
    public function request(Employment $employment, array $input, string $userId): array
    {
        if (! in_array($employment->status, self::OPEN_STATUSES, true)) {
            throw new AyniException('employment_not_open', 422, (string) trans('people.compensation.employment_closed'));
        }
        $date = (string) $input['effective_on'];
        if ($date < $employment->hire_date->toDateString()) {
            throw ValidationException::withMessages(['effective_on' => [(string) trans('people.compensation.before_hire')]]);
        }
        if ($employment->termination_date !== null && $date > $employment->termination_date->toDateString()) {
            throw ValidationException::withMessages(['effective_on' => [(string) trans('people.compensation.after_termination')]]);
        }
        $amount = BigDecimal::of((string) $input['base_salary']['amount'])->toScale(2, RoundingMode::HALF_UP);
        if ($amount->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['base_salary.amount' => [(string) trans('people.compensation.amount_positive')]]);
        }
        $currency = strtoupper((string) $input['base_salary']['currency']);
        $basis = (string) ($input['salary_basis'] ?? 'monthly');
        $country = (string) (DB::table('legal_entities')->where('id', $employment->legal_entity_id)->value('country_code') ?? 'PE');

        $warnings = [];
        $this->checkMinimumWage($employment, $country, $date, $amount, $currency, $basis, $warnings);
        $band = $this->bandAt($employment, $date);
        $bandPosition = $this->bandPosition($band, $amount, $currency);
        if (in_array($bandPosition, ['below', 'above'], true)) {
            $warnings[] = ['code' => 'out_of_band', 'message' => (string) trans('people.compensation.out_of_band_'.$bandPosition)];
        }
        $components = array_key_exists('components', $input) && is_array($input['components'])
            ? $this->normalizeComponents($input['components'], $country, $currency) : null;

        if (CompensationChange::query()->where('employment_id', $employment->id)->where('status', 'pending_approval')
            ->whereDate('effective_on', $date)->exists()) {
            throw new AyniException('pending_change_exists', 409, (string) trans('people.compensation.pending_exists'));
        }

        $relation = ['tenant_id' => TenancyScope::requireTenant(), 'employment_id' => $employment->id];
        $previous = $this->validity->rowAt('employment_compensations', $relation, $date);
        $previousMoney = $previous === null ? null : self::money((string) $previous->base_salary, (string) $previous->currency);
        $increasePct = null;
        if ($previous !== null && $previous->currency === $currency && BigDecimal::of((string) $previous->base_salary)->isPositive()) {
            $increasePct = (string) $amount->minus((string) $previous->base_salary)->multipliedBy(100)
                ->dividedBy((string) $previous->base_salary, 2, RoundingMode::HALF_UP);
        }

        $payload = [
            'base_salary' => self::money((string) $amount, $currency),
            'pay_frequency' => (string) ($input['pay_frequency'] ?? 'monthly'),
            'salary_basis' => $basis,
            'is_integral_salary' => (bool) ($input['is_integral_salary'] ?? false),
            'components' => $components,
            'notes' => $input['notes'] ?? null,
            'previous_salary' => $previousMoney,
            'band_position' => $bandPosition,
            'salary_band_id' => $band?->id,
        ];
        $needsApproval = $this->parameters->value('compensacion.cambio_requiere_aprobacion', $employment->legal_entity_id) !== false;

        $change = DB::transaction(function () use ($employment, $date, $input, $payload, $userId, $needsApproval, $bandPosition, $increasePct): CompensationChange {
            $change = CompensationChange::query()->create([
                'employment_id' => $employment->id,
                'kind' => CompensationChange::KIND,
                'effective_on' => $date,
                'reason' => (string) $input['reason'],
                'status' => 'pending_approval',
                'requested_by_user_id' => $userId,
                'payload' => $payload,
            ]);
            $this->audit->record('compensation_change.requested', 'personnel_action', $change->id, null, [
                'employment_id' => $employment->id, 'effective_on' => $date, 'reason' => $input['reason'], 'approval_required' => $needsApproval,
            ]);
            if (! $needsApproval) {
                $this->apply($change);

                return $change->refresh();
            }
            $approvalId = $this->approvals->start(self::SUBJECT_TYPE, $change->id, $userId, [
                'legal_entity_id' => $employment->legal_entity_id,
                'employment_id' => $employment->id,
                'reason' => $input['reason'],
                'effective_on' => $date,
                'out_of_band' => in_array($bandPosition, ['below', 'above'], true),
                'band_position' => $bandPosition,
                'increase_pct' => $increasePct,
            ]);
            $change->payload = array_merge($change->payload ?? [], ['approval_request_id' => $approvalId]);
            $change->save();

            return $change->refresh();
        });

        return ['change' => $change, 'warnings' => $warnings];
    }

    /**
     * Cancela un cambio pendiente (anula los pasos de aprobación que falten). 409 si ya no está pendiente.
     */
    public function cancel(CompensationChange $change, string $reason): CompensationChange
    {
        return DB::transaction(function () use ($change, $reason): CompensationChange {
            /** @var CompensationChange $locked */
            $locked = CompensationChange::query()->whereKey($change->id)->lockForUpdate()->firstOrFail();
            $next = $this->machine->getNextState($locked->status, 'cancel');
            $this->decide($locked, $next, $reason);
            $approval = $locked->payload['approval_request_id'] ?? null;
            if (is_string($approval)) {
                $this->approvals->cancel($approval, $reason);
            }

            return $locked->refresh();
        });
    }

    // ------------------------------------------------------------------ Resultado del flujo (WFL-4)

    public function approvedByFlow(string $id): void
    {
        $change = CompensationChange::query()->whereKey($id)->lockForUpdate()->first();
        if ($change === null || $change->status !== 'pending_approval') {
            return;
        }
        $this->apply($change);
    }

    public function rejectedByFlow(string $id, ?string $reason): void
    {
        $change = CompensationChange::query()->whereKey($id)->lockForUpdate()->first();
        if ($change === null || $change->status !== 'pending_approval') {
            return;
        }
        $this->decide($change, $this->machine->getNextState($change->status, 'reject'), $reason);
    }

    public function cancelledByFlow(string $id, ?string $reason): void
    {
        $change = CompensationChange::query()->whereKey($id)->lockForUpdate()->first();
        if ($change === null || $change->status !== 'pending_approval') {
            return;
        }
        $this->decide($change, $this->machine->getNextState($change->status, 'cancel'), $reason);
    }

    // ------------------------------------------------------------------ Internos

    /**
     * Registra la compensación aprobada: cierra la vigente el día anterior e inserta la nueva (RN-HIS-01),
     * ajusta los componentes fijos, audita antes y después y emite employment.compensation_changed.
     */
    private function apply(CompensationChange $change): void
    {
        $this->machine->getNextState($change->status, 'apply');
        $p = $change->payload ?? [];
        $date = $change->effective_on->toDateString();
        $tenant = TenancyScope::requireTenant();
        $relation = ['tenant_id' => $tenant, 'employment_id' => $change->employment_id];
        $country = (string) (DB::table('legal_entities')->where('id', DB::table('employments')->where('id', $change->employment_id)->value('legal_entity_id'))->value('country_code') ?? 'PE');

        $before = $this->validity->rowAt('employment_compensations', $relation, $date);
        $beforeComponents = $this->componentsAt($change->employment_id, $date);
        $compensationId = $this->validity->change('employment_compensations', $relation, [
            'base_salary' => (string) $p['base_salary']['amount'],
            'currency' => (string) $p['base_salary']['currency'],
            'pay_frequency' => (string) ($p['pay_frequency'] ?? 'monthly'),
            'salary_basis' => (string) ($p['salary_basis'] ?? 'monthly'),
            'is_integral_salary' => (bool) ($p['is_integral_salary'] ?? false),
            'change_reason' => $change->reason,
            'salary_band_id' => $p['salary_band_id'] ?? null,
            'personnel_action_id' => $change->id,
        ], $date);

        if (is_array($p['components'] ?? null)) {
            $this->applyComponents($relation, $date, $p['components'], $country);
        }

        $change->status = 'applied';
        $change->applied_at = now();
        $change->payload = array_merge($p, ['compensation_id' => $compensationId, 'decided_at' => now()->toIso8601String()]);
        $change->save();

        $this->audit->record('compensation.changed', 'compensation', $compensationId,
            $before === null ? null : [
                'base_salary' => (string) $before->base_salary, 'currency' => $before->currency, 'pay_frequency' => $before->pay_frequency,
                'valid_from' => (string) $before->valid_from, 'components' => $beforeComponents,
            ],
            [
                'base_salary' => (string) $p['base_salary']['amount'], 'currency' => $p['base_salary']['currency'], 'pay_frequency' => $p['pay_frequency'] ?? 'monthly',
                'valid_from' => $date, 'components' => $this->componentsAt($change->employment_id, $date),
            ],
            $change->reason,
        );
        // Contrato del evento (DomainEventTypes): monto, moneda y fecha; lo consume la planilla.
        $this->outbox->record(DomainEventTypes::EMPLOYEE_COMPENSATION_CHANGED, 'employment', $change->employment_id, [
            'employment_id' => $change->employment_id,
            'new_amount' => (string) $p['base_salary']['amount'],
            'currency' => (string) $p['base_salary']['currency'],
            'effective_from' => $date,
            'compensation_id' => $compensationId,
            'personnel_action_id' => $change->id,
            'effective_on' => $date,
            'reason' => $change->reason,
            'retroactive' => $date < now()->toDateString(),
        ]);
    }

    private function decide(CompensationChange $change, string $status, ?string $reason): void
    {
        $change->status = $status;
        $change->payload = array_merge($change->payload ?? [], ['decided_at' => now()->toIso8601String(), 'decision_reason' => $reason]);
        $change->save();
        $this->audit->record('compensation_change.'.$status, 'personnel_action', $change->id, null, ['status' => $status], $reason);
    }

    /**
     * Ajusta los componentes fijos desde $date: los que ya no están se cierran el día anterior; los nuevos
     * o con otro monto abren una vigencia nueva; los que no cambian se dejan como están.
     *
     * @param  array<string, mixed>  $relation
     * @param  array<int, mixed>  $components
     */
    private function applyComponents(array $relation, string $date, array $components, string $country): void
    {
        $current = DB::table('employment_compensation_items')->where('employment_id', $relation['employment_id']);
        EffectiveDated::applyAsOf($current, $date);
        $current = $current->get()->keyBy('payroll_concept_id');

        $wanted = [];
        foreach ($components as $c) {
            $id = $this->concepts->idFor($country, (string) $c['concept_code']);
            if ($id !== null) {
                $wanted[$id] = $c;
            }
        }
        $dayBefore = Carbon::parse($date)->subDay()->toDateString();
        foreach ($current as $conceptId => $row) {
            if (! isset($wanted[$conceptId]) && (string) $row->valid_from < $date) {
                $this->validity->close('employment_compensation_items', $relation + ['payroll_concept_id' => $conceptId], $dayBefore);
            }
        }
        foreach ($wanted as $conceptId => $c) {
            $row = $current->get($conceptId);
            $amount = (string) $c['amount']['amount'];
            $currency = (string) $c['amount']['currency'];
            if ($row !== null && BigDecimal::of((string) $row->amount)->isEqualTo($amount) && $row->currency === $currency) {
                continue;
            }
            $this->validity->change('employment_compensation_items', $relation + ['payroll_concept_id' => $conceptId],
                ['amount' => $amount, 'currency' => $currency], $date);
        }
    }

    /**
     * Componentes fijos vigentes a la fecha.
     *
     * @return list<array{concept_code: string, concept_name: string, amount: array{amount: string, currency: string}}>
     */
    private function componentsAt(string $employmentId, string $date): array
    {
        $query = DB::table('employment_compensation_items as i')
            ->join('payroll_concepts as c', 'c.id', '=', 'i.payroll_concept_id')
            ->where('i.employment_id', $employmentId);
        EffectiveDated::applyAsOf($query, $date, 'i.valid_from', 'i.valid_to');

        return array_values($query->orderBy('c.name')->get(['c.code', 'c.name', 'i.amount', 'i.currency'])
            ->map(fn (\stdClass $r) => [
                'concept_code' => (string) $r->code,
                'concept_name' => (string) $r->name,
                'amount' => self::money((string) $r->amount, (string) $r->currency),
            ])->all());
    }

    /**
     * @param  array<int, mixed>  $components
     * @return list<array{concept_code: string, concept_name: string, amount: array{amount: string, currency: string}}>
     */
    private function normalizeComponents(array $components, string $country, string $currency): array
    {
        $available = collect($this->concepts->available($country))->keyBy('code');
        $out = [];
        $seen = [];
        foreach (array_values($components) as $i => $c) {
            $code = is_array($c) ? (string) ($c['concept_code'] ?? '') : '';
            if (! $available->has($code) || isset($seen[$code])) {
                throw ValidationException::withMessages(["components.{$i}.concept_code" => [(string) trans('people.compensation.invalid_concept')]]);
            }
            $raw = $c['amount']['amount'] ?? null;
            if (! is_numeric($raw) || BigDecimal::of((string) $raw)->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(["components.{$i}.amount" => [(string) trans('people.compensation.amount_positive')]]);
            }
            $seen[$code] = true;
            $out[] = [
                'concept_code' => $code,
                'concept_name' => (string) ($available->get($code)['name'] ?? $code),
                'amount' => self::money((string) BigDecimal::of((string) $raw)->toScale(2, RoundingMode::HALF_UP), strtoupper((string) ($c['amount']['currency'] ?? $currency))),
            ];
        }

        return $out;
    }

    /**
     * Remuneración mínima vigente a la fecha (parámetro legal rmv del país y régimen). Solo se compara un
     * sueldo mensual en la moneda del país; en un contrato a tiempo parcial solo se advierte.
     *
     * @param  list<array{code: string, message: string}>  $warnings
     */
    private function checkMinimumWage(Employment $employment, string $country, string $date, BigDecimal $amount, string $currency, string $basis, array &$warnings): void
    {
        $countryCurrency = DB::table('countries')->where('code', $country)->value('default_currency');
        if ($basis !== 'monthly' || $countryCurrency === null || $currency !== $countryCurrency) {
            return;
        }
        $regime = DB::table('employment_labor_regimes')->where('employment_id', $employment->id);
        EffectiveDated::applyAsOf($regime, $date);
        $rmv = $this->legal->at($country, 'rmv', $date, $regime->value('labor_regime_code'));
        if ($rmv === null || ! is_numeric($rmv['value'])) {
            $warnings[] = ['code' => 'minimum_wage_unverified', 'message' => (string) trans('people.compensation.minimum_unverified')];

            return;
        }
        if ($amount->isGreaterThanOrEqualTo((string) $rmv['value'])) {
            return;
        }
        $contract = DB::table('contracts as k')->join('contract_types as t', 't.id', '=', 'k.contract_type_id')
            ->where('k.employment_id', $employment->id)->whereNull('k.deleted_at');
        EffectiveDated::applyAsOf($contract, $date, 'k.valid_from', 'k.valid_to');
        if ($contract->value('t.category') === 'part_time') {
            $warnings[] = ['code' => 'below_minimum_part_time', 'message' => (string) trans('people.compensation.below_minimum_part_time')];

            return;
        }
        throw new AyniException('below_minimum_wage', 422, (string) trans('people.compensation.below_minimum', ['amount' => $rmv['value']]),
            [['field' => 'base_salary.amount', 'rule' => 'minimum_wage', 'message' => (string) trans('people.compensation.below_minimum', ['amount' => $rmv['value']])]],
            ['minimum_wage' => self::money((string) $rmv['value'], $currency), 'parameter' => 'rmv', 'valid_from' => $rmv['valid_from']]);
    }

    /** Banda salarial de la posición que ocupa la relación a la fecha (referencia), o null. */
    private function bandAt(Employment $employment, string $date): ?\stdClass
    {
        $assignment = DB::table('employment_assignments')->where('employment_id', $employment->id);
        EffectiveDated::applyAsOf($assignment, $date);
        $position = $assignment->value('position_id');
        $bandId = $position === null ? null : DB::table('positions')->where('id', $position)->value('salary_band_id');
        if ($bandId === null) {
            return null;
        }
        $band = DB::table('salary_bands')->where('id', $bandId)->whereNull('deleted_at');
        EffectiveDated::applyAsOf($band, $date);

        /** @var \stdClass|null */
        return $band->first(['id', 'min_amount', 'max_amount', 'currency']);
    }

    private function bandPosition(?\stdClass $band, BigDecimal $amount, string $currency): string
    {
        if ($band === null || $band->currency !== $currency) {
            return 'none';
        }
        if ($amount->isLessThan((string) $band->min_amount)) {
            return 'below';
        }

        return $amount->isGreaterThan((string) $band->max_amount) ? 'above' : 'within';
    }

    /**
     * @return array{amount: string, currency: string}
     */
    private static function money(string $amount, string $currency): array
    {
        return ['amount' => (string) BigDecimal::of($amount)->toScale(2, RoundingMode::HALF_UP), 'currency' => $currency];
    }
}
