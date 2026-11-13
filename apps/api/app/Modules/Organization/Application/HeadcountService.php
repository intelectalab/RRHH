<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Models\HeadcountBudget;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS-4 · Presupuesto de dotación y desviaciones.
 *
 * - El presupuesto se registra por unidad con vigencia (plazas y costo mensual); registrar uno nuevo
 *   cierra el vigente de la unidad el día anterior (nada se sobrescribe).
 * - El tablero cuenta, por unidad, las posiciones aprobadas (todas las plazas no propuestas ni
 *   cerradas), ocupadas, vacantes y congeladas a una fecha y las compara con su presupuesto.
 * - RN-POS-07 · El costo proyectado de cada plaza usa el sueldo vigente del ocupante, o el punto medio
 *   de la banda si está vacante, o su costo presupuestado, más el parámetro de cargas
 *   (posiciones.factor_carga_pct). Los montos son sensibles: el controlador los quita sin
 *   org.positions.budget.view.
 * - La desviación se explica por posición: qué plaza excede las plazas presupuestadas y con qué base
 *   se calculó su costo.
 */
final class HeadcountService
{
    /** Estados que cuentan como plazas aprobadas (consumen presupuesto). */
    public const COUNTED = ['approved', 'open', 'recruiting', 'filled', 'frozen'];

    /** Escala de los montos (dos decimales). */
    private const SCALE = 2;

    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ Presupuestos

    /**
     * @return array<string, mixed>
     */
    public function presentBudget(HeadcountBudget $budget): array
    {
        $row = [
            'id' => $budget->id,
            'legal_entity_id' => $budget->legal_entity_id,
            'org_unit_id' => $budget->org_unit_id,
            'org_unit_name' => DB::table('org_units')->where('id', $budget->org_unit_id)->value('name'),
            'budgeted_positions' => $budget->budgeted_positions,
            'notes' => $budget->notes,
            'valid_from' => $budget->valid_from->toDateString(),
            'valid_to' => $budget->valid_to?->toDateString(),
            'version' => $budget->lock_version,
            'created_at' => $budget->created_at->toIso8601String(),
            'updated_at' => $budget->updated_at->toIso8601String(),
        ];
        if ($budget->monthly_cost !== null && $budget->currency !== null) {
            $row['monthly_cost'] = ['amount' => $this->dec($budget->monthly_cost), 'currency' => $budget->currency];
        }

        return $row;
    }

    /**
     * Registra el presupuesto de una unidad desde una fecha. Si la unidad tiene uno vigente que empezó
     * antes, se cierra el día anterior; si empieza el mismo día, se pide corregirlo (PATCH).
     *
     * @param  array<string, mixed>  $input  ya validado
     */
    public function createBudget(array $input): HeadcountBudget
    {
        $unit = DB::table('org_units')->where('id', $input['org_unit_id'])->whereNull('deleted_at')->first(['id', 'legal_entity_id', 'status']);
        if ($unit === null || $unit->status !== 'active') {
            throw ValidationException::withMessages(['org_unit_id' => [(string) trans('organization.headcount.unknown_unit')]]);
        }
        $entity = (string) ($input['legal_entity_id'] ?? $unit->legal_entity_id ?? '');
        if ($entity === '' || ($unit->legal_entity_id !== null && $unit->legal_entity_id !== $entity)) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.positions.unit_other_company')]]);
        }
        $from = (string) $input['valid_from'];

        return DB::transaction(function () use ($input, $entity, $from): HeadcountBudget {
            $current = HeadcountBudget::query()->where('org_unit_id', $input['org_unit_id'])
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))
                ->orderByDesc('valid_from')->lockForUpdate()->get();
            foreach ($current as $old) {
                if ($old->valid_from->toDateString() >= $from) {
                    throw new AyniException('conflict', 409, (string) trans('organization.headcount.budget_overlap'));
                }
                $before = $this->presentBudget($old);
                $old->forceFill(['valid_to' => EffectiveDated::day(now()->parse($from)->subDay())])->save();
                $this->audit->record('headcount_budget.closed', 'headcount_budget', $old->id, $before, $this->presentBudget($old->refresh()));
            }
            $budget = HeadcountBudget::create([
                'legal_entity_id' => $entity,
                'org_unit_id' => $input['org_unit_id'],
                'budgeted_positions' => (int) $input['budgeted_positions'],
                'monthly_cost' => isset($input['monthly_cost']) ? (string) $input['monthly_cost']['amount'] : null,
                'currency' => isset($input['monthly_cost']) ? strtoupper((string) $input['monthly_cost']['currency']) : null,
                'notes' => $input['notes'] ?? null,
                'valid_from' => $from,
                'valid_to' => $input['valid_to'] ?? null,
            ]);
            $budget->refresh();
            $this->audit->record('headcount_budget.created', 'headcount_budget', $budget->id, null, $this->presentBudget($budget));

            return $budget;
        });
    }

    /**
     * Corrige plazas, costo, notas o fin de vigencia de un presupuesto (el inicio no cambia: para otro
     * periodo se registra un presupuesto nuevo).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateBudget(HeadcountBudget $budget, array $input, ?int $expectedVersion): HeadcountBudget
    {
        if ($expectedVersion !== null && $expectedVersion !== $budget->lock_version) {
            throw new VersionConflictException($expectedVersion, $budget->lock_version);
        }
        $values = [];
        foreach (['budgeted_positions', 'notes', 'valid_to'] as $f) {
            if (array_key_exists($f, $input)) {
                $values[$f] = $input[$f];
            }
        }
        if (array_key_exists('monthly_cost', $input)) {
            $values['monthly_cost'] = is_array($input['monthly_cost']) ? (string) $input['monthly_cost']['amount'] : null;
            $values['currency'] = is_array($input['monthly_cost']) ? strtoupper((string) $input['monthly_cost']['currency']) : null;
        }
        if (isset($values['valid_to']) && is_string($values['valid_to']) && $values['valid_to'] < $budget->valid_from->toDateString()) {
            throw ValidationException::withMessages(['valid_to' => [(string) trans('organization.positions.valid_to_before')]]);
        }

        return DB::transaction(function () use ($budget, $values): HeadcountBudget {
            $before = $this->presentBudget($budget);
            try {
                $budget->fill($values)->save();
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'ex_headcount_budgets_vigencia')) {
                    throw new AyniException('conflict', 409, (string) trans('organization.headcount.budget_overlap'));
                }
                throw $e;
            }
            $budget->refresh();
            $this->audit->record('headcount_budget.updated', 'headcount_budget', $budget->id, $before, $this->presentBudget($budget));

            return $budget;
        });
    }

    public function budgetAt(string $orgUnitId, string $date): ?HeadcountBudget
    {
        /** @var HeadcountBudget|null */
        return HeadcountBudget::query()->where('org_unit_id', $orgUnitId)->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))->first();
    }

    // ------------------------------------------------------------------ Disponibilidad (RN-POS-05)

    /**
     * Presupuesto disponible de una unidad a una fecha: plazas y costo mensual que quedan libres.
     * Sin presupuesto registrado no hay tope (has_budget = false).
     *
     * @return array{has_budget: bool, budgeted_positions: int|null, committed_positions: int, available_positions: int|null, monthly_cost: array{amount: string, currency: string}|null, committed_cost: array{amount: string, currency: string}|null, available_cost: array{amount: string, currency: string}|null}
     */
    public function availability(string $orgUnitId, string $date, ?string $exceptPositionId = null): array
    {
        $budget = $this->budgetAt($orgUnitId, $date);
        $positions = $this->positionsAt($date, [$orgUnitId])->filter(fn (Position $p) => in_array($p->status, self::COUNTED, true) && $p->id !== $exceptPositionId);
        $committed = (int) $positions->sum(fn (Position $p) => $p->seats);
        if (! $budget instanceof HeadcountBudget) {
            return ['has_budget' => false, 'budgeted_positions' => null, 'committed_positions' => $committed, 'available_positions' => null,
                'monthly_cost' => null, 'committed_cost' => null, 'available_cost' => null];
        }
        $cost = null;
        $committedCost = null;
        $availableCost = null;
        if ($budget->monthly_cost !== null && $budget->currency !== null) {
            $costs = $this->costs($positions->all(), $date);
            $sum = '0';
            foreach ($costs as $c) {
                if ($c['projected'] !== null && $c['currency'] === $budget->currency) {
                    $sum = self::add($sum, $c['projected'], self::SCALE);
                }
            }
            $cost = ['amount' => $this->dec($budget->monthly_cost), 'currency' => $budget->currency];
            $committedCost = ['amount' => $this->dec($sum), 'currency' => $budget->currency];
            $availableCost = ['amount' => $this->dec(self::sub($this->dec($budget->monthly_cost), $sum, self::SCALE)), 'currency' => $budget->currency];
        }

        return [
            'has_budget' => true,
            'budgeted_positions' => $budget->budgeted_positions,
            'committed_positions' => $committed,
            'available_positions' => $budget->budgeted_positions - $committed,
            'monthly_cost' => $cost,
            'committed_cost' => $committedCost,
            'available_cost' => $availableCost,
        ];
    }

    /**
     * ¿Una plaza más (o `seats` plazas con `monthlyCost` cada una) excede el presupuesto de la unidad?
     *
     * @param  array{amount: string, currency: string}|null  $monthlyCost  costo estimado por plaza
     */
    public function exceeds(string $orgUnitId, string $date, int $seats, ?array $monthlyCost, ?string $exceptPositionId = null): bool
    {
        $a = $this->availability($orgUnitId, $date, $exceptPositionId);
        if (! $a['has_budget']) {
            return false;
        }
        if ($seats > (int) $a['available_positions']) {
            return true;
        }
        if ($monthlyCost !== null && $a['available_cost'] !== null && $a['available_cost']['currency'] === strtoupper($monthlyCost['currency'])) {
            $needed = self::mul($this->dec($monthlyCost['amount']), (string) $seats, self::SCALE);

            return self::cmp($needed, $a['available_cost']['amount'], self::SCALE) > 0;
        }

        return false;
    }

    /**
     * Costo mensual estimado de una plaza nueva sin ocupante (para el control de presupuesto al aprobar).
     *
     * @return array{amount: string, currency: string}|null
     */
    public function positionCost(Position $position, string $date): ?array
    {
        $c = $this->costs([$position], $date)[$position->id] ?? null;

        return $c === null || $c['projected'] === null || $c['currency'] === null ? null : ['amount' => $c['projected'], 'currency' => $c['currency']];
    }

    // ------------------------------------------------------------------ Tablero

    /**
     * Tablero por unidad a una fecha. Con $unitIds solo esas unidades (y sus subunidades);
     * con $entityIds (alcance) solo las de esas empresas.
     *
     * @param  array<int, string>|null  $entityIds  null = todas
     * @return array{units: array<int, array<string, mixed>>, totals: array<string, mixed>, load_factor_pct: string}
     */
    public function dashboard(string $date, ?array $entityIds, ?string $legalEntityId, ?string $orgUnitId): array
    {
        $unitQuery = DB::table('org_units')->whereNull('deleted_at');
        if ($entityIds !== null) {
            $unitQuery->whereIn('legal_entity_id', $entityIds);
        }
        if ($legalEntityId !== null) {
            $unitQuery->where('legal_entity_id', $legalEntityId);
        }
        $units = $unitQuery->orderBy('name')->get(['id', 'name', 'parent_id', 'legal_entity_id', 'status']);
        if ($orgUnitId !== null) {
            $keep = $this->subtree($orgUnitId);
            $units = $units->filter(fn ($u) => isset($keep[(string) $u->id]))->values();
        }
        $unitIds = $units->pluck('id')->map(fn ($id) => (string) $id)->all();
        $positions = $this->positionsAt($date, $unitIds)->groupBy('org_unit_id');
        $budgets = HeadcountBudget::query()->whereIn('org_unit_id', $unitIds)->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))->get()->keyBy('org_unit_id');
        $currency = (string) ($this->parameters->value('org.moneda_defecto') ?? 'PEN');

        $rows = [];
        $totals = ['proposed' => 0, 'approved' => 0, 'filled' => 0, 'vacant' => 0, 'recruiting' => 0, 'frozen' => 0, 'budgeted_positions' => 0, 'deviation_positions' => 0];
        $totalCost = [];
        $totalBudget = [];
        foreach ($units as $u) {
            $list = $positions->get($u->id, collect())->all();
            $budget = $budgets->get($u->id);
            if ($list === [] && ! $budget instanceof HeadcountBudget) {
                continue;
            }
            $counts = $this->counts($list);
            $row = [
                'org_unit_id' => (string) $u->id,
                'org_unit_name' => (string) $u->name,
                'parent_id' => $u->parent_id === null ? null : (string) $u->parent_id,
                'legal_entity_id' => $u->legal_entity_id === null ? null : (string) $u->legal_entity_id,
                'counts' => $counts,
                'budgeted_positions' => $budget instanceof HeadcountBudget ? $budget->budgeted_positions : null,
                'deviation_positions' => $budget instanceof HeadcountBudget ? $counts['approved'] - $budget->budgeted_positions : null,
                'budget_id' => $budget instanceof HeadcountBudget ? $budget->id : null,
            ];
            // Costo: en la moneda del presupuesto (o la de la organización); las plazas en otra moneda se cuentan aparte.
            $rowCurrency = $budget instanceof HeadcountBudget && $budget->currency !== null ? $budget->currency : $currency;
            $counted = array_filter($list, fn (Position $p) => in_array($p->status, self::COUNTED, true));
            $costs = $this->costs($counted, $date);
            $sum = '0';
            $unknown = 0;
            $otherCurrency = 0;
            foreach ($costs as $c) {
                if ($c['projected'] === null) {
                    $unknown++;
                } elseif ($c['currency'] !== $rowCurrency) {
                    $otherCurrency++;
                } else {
                    $sum = self::add($sum, $c['projected'], self::SCALE);
                }
            }
            $row['projected_cost'] = ['amount' => $this->dec($sum), 'currency' => $rowCurrency];
            $row['positions_without_cost'] = $unknown;
            $row['positions_other_currency'] = $otherCurrency;
            if ($budget instanceof HeadcountBudget && $budget->monthly_cost !== null && $budget->currency !== null) {
                $row['budgeted_cost'] = ['amount' => $this->dec($budget->monthly_cost), 'currency' => $budget->currency];
                $row['deviation_cost'] = ['amount' => $this->dec(self::sub($sum, $this->dec($budget->monthly_cost), self::SCALE)), 'currency' => $budget->currency];
                $totalBudget[$budget->currency] = self::add($totalBudget[$budget->currency] ?? '0', $this->dec($budget->monthly_cost), self::SCALE);
            }
            $totalCost[$rowCurrency] = self::add($totalCost[$rowCurrency] ?? '0', $sum, self::SCALE);
            foreach (['proposed', 'approved', 'filled', 'vacant', 'recruiting', 'frozen'] as $k) {
                $totals[$k] += $counts[$k];
            }
            if ($budget instanceof HeadcountBudget) {
                $totals['budgeted_positions'] += $budget->budgeted_positions;
                $totals['deviation_positions'] += $counts['approved'] - $budget->budgeted_positions;
            }
            $rows[] = $row;
        }
        $totals['projected_cost'] = array_map(fn ($c, $a) => ['amount' => $this->dec($a), 'currency' => $c], array_keys($totalCost), $totalCost);
        $totals['budgeted_cost'] = array_map(fn ($c, $a) => ['amount' => $this->dec($a), 'currency' => $c], array_keys($totalBudget), $totalBudget);

        return ['units' => $rows, 'totals' => $totals, 'load_factor_pct' => $this->dec($this->loadFactor($legalEntityId, $date))];
    }

    /**
     * Desviación explicada por posición de una unidad: cada plaza con su base de costo, sus cargas y si
     * entra en las plazas presupuestadas (por orden de antigüedad) o las excede.
     *
     * @return array{org_unit_id: string, budgeted_positions: int|null, positions: array<int, array<string, mixed>>}
     */
    public function explain(string $orgUnitId, string $date): array
    {
        $budget = $this->budgetAt($orgUnitId, $date);
        $list = $this->positionsAt($date, [$orgUnitId])->sortBy(fn (Position $p) => $p->valid_from->toDateString().'|'.$p->created_at->format('Y-m-d\TH:i:s.u').'|'.$p->id)->values()->all();
        $counted = array_values(array_filter($list, fn (Position $p) => in_array($p->status, self::COUNTED, true)));
        $costs = $this->costs($list, $date);
        $seatsUsed = 0;
        $out = [];
        foreach ($list as $p) {
            $c = $costs[$p->id];
            $isCounted = in_array($p, $counted, true);
            $status = 'not_counted';
            if ($isCounted) {
                if (! $budget instanceof HeadcountBudget) {
                    $status = 'no_budget';
                } else {
                    $seatsUsed += $p->seats;
                    $status = $seatsUsed <= $budget->budgeted_positions ? 'within' : 'over_budget';
                }
            }
            $row = [
                'position_id' => $p->id,
                'code' => $p->code,
                'title' => $p->title,
                'status' => $p->status,
                'seats' => $p->seats,
                'counts_against_budget' => $isCounted,
                'budget_status' => $status,
                'cost_basis' => $c['basis'],
                'load_factor_pct' => $this->dec($c['factor']),
                'explanation' => $this->explanation($status, $c['basis'], $seatsUsed, $budget?->budgeted_positions, $c['factor']),
            ];
            if ($c['base'] !== null && $c['currency'] !== null) {
                $row['base_cost'] = ['amount' => $c['base'], 'currency' => $c['currency']];
            }
            if ($c['projected'] !== null && $c['currency'] !== null) {
                $row['projected_cost'] = ['amount' => $c['projected'], 'currency' => $c['currency']];
            }
            $out[] = $row;
        }

        return ['org_unit_id' => $orgUnitId, 'budgeted_positions' => $budget?->budgeted_positions, 'positions' => $out];
    }

    // ------------------------------------------------------------------ Cálculo

    /**
     * Posiciones vigentes a la fecha en las unidades indicadas (sin las cerradas).
     *
     * @param  array<int, string>  $unitIds
     * @return Collection<int, Position>
     */
    public function positionsAt(string $date, array $unitIds)
    {
        if ($unitIds === []) {
            return collect();
        }

        return Position::query()->whereIn('org_unit_id', $unitIds)->where('status', '<>', 'closed')
            ->where('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderBy('valid_from')->orderBy('created_at')->get();
    }

    /**
     * RN-POS-07 · Base y costo proyectado mensual de cada plaza.
     *
     * @param  array<int, Position>  $positions
     * @return array<string, array{basis: string, base: string|null, projected: string|null, currency: string|null, factor: string}>
     */
    private function costs(array $positions, string $date): array
    {
        if ($positions === []) {
            return [];
        }
        $ids = array_map(fn (Position $p) => $p->id, $positions);
        // Sueldo vigente de los ocupantes (solo sueldos mensuales: los demás no se estiman aquí).
        $salaries = DB::table('employment_assignments as a')
            ->join('employment_compensations as c', 'c.employment_id', '=', 'a.employment_id')
            ->whereIn('a.position_id', $ids)
            ->where('a.valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $date))
            ->where('c.valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('c.valid_to')->orWhere('c.valid_to', '>=', $date))
            ->where('c.salary_basis', 'monthly')
            ->get(['a.position_id', 'c.base_salary', 'c.currency']);
        $byPosition = [];
        foreach ($salaries as $s) {
            $byPosition[(string) $s->position_id][] = ['amount' => $this->dec((string) $s->base_salary), 'currency' => $s->currency === null ? null : (string) $s->currency];
        }
        $bandIds = array_values(array_unique(array_filter(array_map(fn (Position $p) => $p->salary_band_id, $positions))));
        // Punto medio y moneda de cada banda (sin punto medio registrado: el promedio de mínimo y máximo).
        $bands = [];
        if ($bandIds !== []) {
            foreach (DB::table('salary_bands')->whereIn('id', $bandIds)->whereNull('deleted_at')->get(['id', 'min_amount', 'mid_amount', 'max_amount', 'currency']) as $b) {
                $bands[(string) $b->id] = [
                    'mid' => $b->mid_amount !== null ? $this->dec((string) $b->mid_amount)
                        : self::div(self::add($this->dec((string) $b->min_amount), $this->dec((string) $b->max_amount), self::SCALE), '2', self::SCALE),
                    'currency' => $b->currency === null ? null : (string) $b->currency,
                ];
            }
        }
        $factors = [];

        $out = [];
        foreach ($positions as $p) {
            $factor = $factors[$p->legal_entity_id] ??= $this->loadFactor($p->legal_entity_id, $date);
            $base = null;
            $currency = null;
            $basis = 'none';
            $occupied = $byPosition[$p->id] ?? [];
            $band = $p->salary_band_id === null ? null : ($bands[$p->salary_band_id] ?? null);
            $mid = $band['mid'] ?? null;
            if ($occupied !== []) {
                $basis = 'occupant_salary';
                $currency = $occupied[0]['currency'];
                $base = '0';
                foreach ($occupied as $o) {
                    $base = self::add($base, $o['amount'], self::SCALE);
                }
                // Cupos vacantes de una posición compartida: punto medio de la banda.
                $vacant = max(0, $p->seats - count($occupied));
                if ($vacant > 0 && $mid !== null && $band !== null && $band['currency'] === $currency) {
                    $base = self::add($base, self::mul($mid, (string) $vacant, self::SCALE), self::SCALE);
                }
            } elseif ($mid !== null && $band !== null) {
                $basis = 'band_midpoint';
                $currency = $band['currency'];
                $base = self::mul($mid, (string) $p->seats, self::SCALE);
            } elseif ($p->budgeted_cost !== null && $p->currency !== null) {
                $basis = 'budgeted_cost';
                $currency = $p->currency;
                $base = $this->dec($p->budgeted_cost);
            }
            $projected = $base === null ? null
                : self::add($base, self::div(self::mul($base, $factor, 6), '100', 6), self::SCALE);
            $out[$p->id] = ['basis' => $basis, 'base' => $base === null ? null : $this->dec($base), 'projected' => $projected === null ? null : $this->dec($projected), 'currency' => $currency, 'factor' => $factor];
        }

        return $out;
    }

    /** Parámetro de cargas (porcentaje) de la empresa a la fecha; 0 si no está definido. */
    private function loadFactor(?string $legalEntityId, string $date): string
    {
        $value = $this->parameters->value('posiciones.factor_carga_pct', $legalEntityId, $date);

        return is_numeric($value) ? $this->dec((string) $value) : '0.00';
    }

    /**
     * @param  array<int, Position>  $positions
     * @return array{proposed: int, approved: int, filled: int, vacant: int, recruiting: int, frozen: int}
     */
    private function counts(array $positions): array
    {
        $c = ['proposed' => 0, 'approved' => 0, 'filled' => 0, 'vacant' => 0, 'recruiting' => 0, 'frozen' => 0];
        foreach ($positions as $p) {
            $seats = $p->seats;
            match ($p->status) {
                'proposed' => $c['proposed'] += $seats,
                'filled' => $c['filled'] += $seats,
                'frozen' => $c['frozen'] += $seats,
                'recruiting' => $c['recruiting'] += $seats,
                default => null,
            };
            if (in_array($p->status, ['approved', 'open', 'recruiting'], true)) {
                $c['vacant'] += $seats;
            }
            if (in_array($p->status, self::COUNTED, true)) {
                $c['approved'] += $seats;
            }
        }

        return $c;
    }

    private function explanation(string $status, string $basis, int $seatsUsed, ?int $budgeted, string $factor): string
    {
        $parts = [(string) trans('organization.headcount.explain_status.'.$status, ['seat' => $seatsUsed, 'budgeted' => $budgeted ?? 0])];
        $parts[] = (string) trans('organization.headcount.explain_basis.'.$basis, ['factor' => rtrim(rtrim($factor, '0'), '.')]);

        return implode(' ', $parts);
    }

    /**
     * La unidad y todas sus subunidades (estructura vigente).
     *
     * @return array<string, true>
     */
    private function subtree(string $unitId): array
    {
        $children = [];
        foreach (DB::table('org_units')->whereNull('deleted_at')->get(['id', 'parent_id']) as $u) {
            if ($u->parent_id !== null) {
                $children[(string) $u->parent_id][] = (string) $u->id;
            }
        }
        $out = [$unitId => true];
        $stack = [$unitId];
        while ($stack !== []) {
            $id = array_pop($stack);
            foreach ($children[$id] ?? [] as $child) {
                if (! isset($out[$child])) {
                    $out[$child] = true;
                    $stack[] = $child;
                }
            }
        }

        return $out;
    }

    private function dec(string $value): string
    {
        return self::add($value, '0', self::SCALE);
    }

    // Aritmética decimal exacta (regla 12: nunca float).
    private static function add(string $a, string $b, int $scale): string
    {
        return (string) BigDecimal::of($a)->plus(BigDecimal::of($b))->toScale($scale, RoundingMode::HALF_UP);
    }

    private static function sub(string $a, string $b, int $scale): string
    {
        return (string) BigDecimal::of($a)->minus(BigDecimal::of($b))->toScale($scale, RoundingMode::HALF_UP);
    }

    private static function mul(string $a, string $b, int $scale): string
    {
        return (string) BigDecimal::of($a)->multipliedBy(BigDecimal::of($b))->toScale($scale, RoundingMode::HALF_UP);
    }

    private static function div(string $a, string $b, int $scale): string
    {
        return (string) BigDecimal::of($a)->dividedBy(BigDecimal::of($b), $scale, RoundingMode::HALF_UP);
    }

    private static function cmp(string $a, string $b, int $scale): int
    {
        return BigDecimal::of($a)->toScale($scale, RoundingMode::HALF_UP)->compareTo(BigDecimal::of($b)->toScale($scale, RoundingMode::HALF_UP));
    }
}
