<?php

namespace App\Modules\Payroll\Application;

use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollIncident;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Settings\ParameterResolver;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;

/**
 * PER-2 · Incidencias del periodo: la única vía de los valores variables al cálculo.
 *
 * - Cada incidencia guarda su origen (formulario, carga masiva o módulo) y su autor.
 * - Las que vienen de otro módulo (ausencias, vacaciones, préstamos…) no se editan aquí: se corrigen en su
 *   origen (RN-PER-09).
 * - Una manual cuyo monto supera nomina.incidencia_monto_visto_bueno queda pendiente de visto bueno (RN-PER-10);
 *   el resto entra aprobada.
 * - Se reciben y corrigen con el periodo abierto o calculado; desde el envío a aprobación quedan bloqueadas y,
 *   al cerrar, aplicadas (RN-PER-03).
 */
final class PayrollIncidents
{
    public const EDITABLE_SOURCES = ['manual', 'import'];

    public function __construct(
        private readonly PayrollPeriods $periods,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data  employment_id, payroll_concept_id o concept_code, quantity, rate, amount, notes
     */
    public function create(PayrollPeriod $p, array $data, ?string $userId, string $source = 'manual'): PayrollIncident
    {
        $this->assertOpen($p);
        $row = $this->normalize($p, $data, $this->periods->population($p));
        if (isset($row['error'])) {
            throw new AyniException('validation_failed', 422, $row['error']['message'], [$row['error']]);
        }

        return DB::transaction(function () use ($p, $row, $userId, $source): PayrollIncident {
            $i = PayrollIncident::query()->create($row['values'] + [
                'payroll_period_id' => $p->id, 'source' => $source, 'created_by_user_id' => $userId,
                'status' => $this->initialStatus($p, $row['values']['amount'] ?? null),
            ]);
            $this->audit->record('payroll_incident.created', 'payroll_incident', $i->id, null, $this->snapshot($i));

            return $i;
        });
    }

    /**
     * Carga masiva: todo o nada; si alguna fila no procede, nada se guarda y se informa el error de cada una.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<PayrollIncident>
     */
    public function bulk(PayrollPeriod $p, array $rows, ?string $userId): array
    {
        $this->assertOpen($p);
        $population = $this->periods->population($p);
        $normalized = [];
        $errors = [];
        foreach ($rows as $n => $data) {
            $row = $this->normalize($p, $data, $population);
            if (isset($row['error'])) {
                $errors[] = ['field' => 'rows.'.$n.'.'.$row['error']['field'], 'rule' => $row['error']['rule'], 'message' => $row['error']['message']];

                continue;
            }
            $normalized[] = $row['values'];
        }
        if ($errors !== []) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.incidents.bulk_errors', ['count' => count($errors)]), $errors);
        }

        return DB::transaction(function () use ($p, $normalized, $userId): array {
            $created = [];
            foreach ($normalized as $values) {
                $i = PayrollIncident::query()->create($values + [
                    'payroll_period_id' => $p->id, 'source' => 'import', 'source_type' => 'bulk', 'created_by_user_id' => $userId,
                    'status' => $this->initialStatus($p, $values['amount'] ?? null),
                ]);
                $created[] = $i;
            }
            $this->audit->record('payroll_incident.bulk_loaded', 'payroll_period', $p->id, null, ['count' => count($created)]);

            return $created;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(PayrollIncident $i, array $data, ?string $userId): PayrollIncident
    {
        $p = PayrollPeriod::query()->findOrFail($i->payroll_period_id);
        $this->assertEditable($i, $p);
        $merged = array_merge(['employment_id' => $i->employment_id, 'payroll_concept_id' => $i->payroll_concept_id,
            'quantity' => $i->quantity, 'rate' => $i->rate, 'amount' => $i->amount, 'notes' => $i->notes], $data);
        if (isset($data['concept_code'])) {
            unset($merged['payroll_concept_id']);
        }
        $row = $this->normalize($p, $merged, $this->periods->population($p));
        if (isset($row['error'])) {
            throw new AyniException('validation_failed', 422, $row['error']['message'], [$row['error']]);
        }
        $before = $this->snapshot($i);

        return DB::transaction(function () use ($i, $p, $row, $userId, $before): PayrollIncident {
            $i->fill($row['values'] + ['updated_by_user_id' => $userId, 'status' => $this->initialStatus($p, $row['values']['amount'] ?? null),
                'decided_by_user_id' => null, 'decided_at' => null, 'decision_reason' => null])->save();
            $this->audit->record('payroll_incident.updated', 'payroll_incident', $i->id, $before, $this->snapshot($i));

            return $i;
        });
    }

    /** Pasa a la papelera (nunca se borra físicamente). */
    public function delete(PayrollIncident $i, ?string $userId): void
    {
        $p = PayrollPeriod::query()->findOrFail($i->payroll_period_id);
        $this->assertEditable($i, $p);
        DB::transaction(function () use ($i, $userId): void {
            $i->forceFill(['updated_by_user_id' => $userId])->save();
            $i->delete();
            $this->audit->record('payroll_incident.deleted', 'payroll_incident', $i->id, $this->snapshot($i), null);
        });
    }

    public function decide(PayrollIncident $i, string $action, ?string $userId, ?string $reason): PayrollIncident
    {
        if ($i->status !== 'pending') {
            throw new InvalidTransitionException(currentStatus: $i->status, allowedActions: [], message: (string) trans('payroll.incidents.not_pending'));
        }
        $p = PayrollPeriod::query()->findOrFail($i->payroll_period_id);
        $this->assertOpen($p);
        if ($action === 'reject' && ($reason === null || trim($reason) === '')) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.periods.reason_required'),
                [['field' => 'reason', 'rule' => 'required', 'message' => (string) trans('payroll.periods.reason_required')]]);
        }
        $i->fill(['status' => $action === 'approve' ? 'approved' : 'rejected', 'decided_by_user_id' => $userId, 'decided_at' => now(), 'decision_reason' => $reason])->save();
        $this->audit->record('payroll_incident.'.($action === 'approve' ? 'approved' : 'rejected'), 'payroll_incident', $i->id, ['status' => 'pending'], ['status' => $i->status], $reason);

        return $i;
    }

    /**
     * @param  iterable<PayrollIncident>  $incidents
     * @return list<array<string, mixed>>
     */
    public function presentMany(iterable $incidents): array
    {
        $list = collect($incidents)->values();
        $people = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->whereIn('e.id', $list->pluck('employment_id')->unique()->all())
            ->get(['e.id', 'e.employee_code', DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as n")])->keyBy('id');
        $concepts = DB::table('payroll_concepts')->whereIn('id', $list->pluck('payroll_concept_id')->unique()->all())->get(['id', 'code', 'name'])->keyBy('id');
        $users = DB::table('users')->whereIn('id', $list->pluck('created_by_user_id')->filter()->unique()->all())->pluck('display_name', 'id');
        $periods = PayrollPeriod::query()->whereIn('id', $list->pluck('payroll_period_id')->unique()->all())->get()->keyBy('id');

        return array_values($list->map(function (PayrollIncident $i) use ($people, $concepts, $users, $periods): array {
            $person = $people[$i->employment_id] ?? null;
            $concept = $concepts[$i->payroll_concept_id] ?? null;
            $period = $periods[$i->payroll_period_id] ?? null;
            $editable = $period instanceof PayrollPeriod && $this->periods->isEditable($period)
                && in_array($i->source, self::EDITABLE_SOURCES, true) && $i->status !== 'applied';

            return array_filter([
                'id' => $i->id, 'payroll_period_id' => $i->payroll_period_id, 'employment_id' => $i->employment_id,
                'person_name' => $person?->n, 'employee_code' => $person?->employee_code,
                'payroll_concept_id' => $i->payroll_concept_id, 'concept_code' => $concept?->code, 'concept_name' => $concept?->name,
                'quantity' => $i->quantity === null ? null : (string) BigDecimal::of($i->quantity)->stripTrailingZeros(),
                'rate' => $i->rate === null ? null : (string) BigDecimal::of($i->rate)->stripTrailingZeros(),
                'amount' => $i->amount === null ? null : ['amount' => (string) BigDecimal::of($i->amount)->toScale(2), 'currency' => (string) ($i->currency ?? 'PEN')],
                'source' => $i->source, 'source_type' => $i->source_type, 'source_id' => $i->source_id, 'retro_period_id' => $i->retro_period_id,
                'notes' => $i->notes, 'status' => $i->status, 'created_by_name' => $i->created_by_user_id === null ? null : ($users[$i->created_by_user_id] ?? null),
                'decided_at' => $i->decided_at?->toIso8601String(), 'decision_reason' => $i->decision_reason,
                'editable' => $editable,
                'allowed_actions' => $i->status === 'pending' && $period instanceof PayrollPeriod && $this->periods->isEditable($period) ? ['approve', 'reject'] : [],
            ], fn ($v) => $v !== null);
        })->all());
    }

    private function assertOpen(PayrollPeriod $p): void
    {
        if (! $this->periods->isEditable($p)) {
            throw new AyniException('period_locked', 409, (string) trans('payroll.incidents.period_locked'));
        }
    }

    private function assertEditable(PayrollIncident $i, PayrollPeriod $p): void
    {
        $this->assertOpen($p);
        if (! in_array($i->source, self::EDITABLE_SOURCES, true)) {
            throw new AyniException('managed_by_source', 409, (string) trans('payroll.incidents.managed_by_source'));
        }
        if ($i->status === 'applied') {
            throw new AyniException('period_locked', 409, (string) trans('payroll.incidents.period_locked'));
        }
    }

    /** RN-PER-10: sobre el monto del parámetro, la incidencia manual espera visto bueno. */
    private function initialStatus(PayrollPeriod $p, ?string $amount): string
    {
        if ($amount === null) {
            return 'approved';
        }
        $entity = PayrollGroup::query()->whereKey($p->payroll_group_id)->value('legal_entity_id');
        $raw = $this->parameters->value('nomina.incidencia_monto_visto_bueno', is_string($entity) ? $entity : null);
        try {
            $threshold = BigDecimal::of(is_numeric($raw) ? (string) $raw : '0');
        } catch (MathException) {
            return 'approved';
        }

        return $threshold->isPositive() && BigDecimal::of($amount)->abs()->isGreaterThan($threshold) ? 'pending' : 'approved';
    }

    /**
     * Valida y normaliza una fila: trabajador de la población, concepto vigente y al menos cantidad o monto.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $population
     * @return array{values: array<string, mixed>}|array{error: array{field: string, rule: string, message: string}}
     */
    private function normalize(PayrollPeriod $p, array $data, array $population): array
    {
        $fail = fn (string $field, string $rule, string $key, array $r = []): array => ['error' => ['field' => $field, 'rule' => $rule, 'message' => (string) trans('payroll.incidents.'.$key, $r)]];
        $employment = $data['employment_id'] ?? null;
        if ((! is_string($employment) || $employment === '') && isset($data['employee_code']) && is_string($data['employee_code'])) {
            $employment = DB::table('employments')->whereIn('id', $population)->where('employee_code', trim($data['employee_code']))->value('id');
        }
        if (! is_string($employment) || ! in_array($employment, $population, true)) {
            return $fail('employment_id', 'exists', 'not_in_population');
        }
        $conceptQuery = DB::table('payroll_concepts')->whereNull('deleted_at');
        if (isset($data['payroll_concept_id']) && is_string($data['payroll_concept_id']) && preg_match('/^[0-9a-f-]{36}$/i', $data['payroll_concept_id']) === 1) {
            $conceptQuery->where('id', $data['payroll_concept_id']);
        } elseif (isset($data['concept_code']) && is_string($data['concept_code']) && $data['concept_code'] !== '') {
            $conceptQuery->where('code', trim($data['concept_code']));
        } else {
            return $fail('concept_code', 'required', 'concept_required');
        }
        $concept = $conceptQuery->first(['id', 'status']);
        if ($concept === null || (isset($concept->status) && $concept->status !== 'active')) {
            return $fail('concept_code', 'exists', 'concept_unknown');
        }
        $values = ['employment_id' => $employment, 'payroll_concept_id' => (string) $concept->id];
        foreach (['quantity' => 4, 'rate' => 6, 'amount' => 2] as $field => $scale) {
            $raw = $data[$field] ?? null;
            if ($raw === null || $raw === '') {
                $values[$field] = null;

                continue;
            }
            try {
                $values[$field] = (string) BigDecimal::of(is_scalar($raw) ? (string) $raw : '')->toScale($scale);
            } catch (MathException) {
                return $fail($field, 'numeric', 'not_a_number');
            }
        }
        if ($values['quantity'] === null && $values['amount'] === null) {
            return $fail('amount', 'required', 'value_required');
        }
        $values['currency'] = $values['amount'] === null ? null
            : (string) (PayrollGroup::query()->whereKey($p->payroll_group_id)->value('currency') ?? 'PEN');
        $notes = $data['notes'] ?? null;
        $values['notes'] = is_string($notes) && trim($notes) !== '' ? mb_substr(trim($notes), 0, 500) : null;

        return ['values' => $values];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PayrollIncident $i): array
    {
        return $i->only(['payroll_period_id', 'employment_id', 'payroll_concept_id', 'quantity', 'rate', 'amount', 'currency', 'source', 'status', 'notes']);
    }
}
