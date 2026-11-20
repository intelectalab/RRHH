<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\HeadcountScenarioStateMachine;
use App\Modules\Organization\Infrastructure\Models\HeadcountPlanLine;
use App\Modules\Organization\Infrastructure\Models\HeadcountScenario;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * POS-5 · Escenarios de planificación de la dotación.
 *
 * - Crear un escenario copia las posiciones vigentes a base_as_of (las de su empresa, o todas las que el usuario
 *   puede ver) como líneas «baseline» con su costo proyectado (RN-POS-07). Un escenario «actual» es esa copia sin
 *   cambios; los demás suman plazas (add), congelan (freeze) o quitan (remove) posiciones.
 * - Nada de esto toca posiciones reales (RN-POS-08): solo aplicar un escenario aprobado crea solicitudes de posición
 *   en borrador por lo agregado y congela o cierra las posiciones marcadas. Lo que no se puede aplicar (una posición
 *   ocupada no se cierra, RN-POS-06) queda en applied_summary con su motivo y no detiene el resto.
 * - El escenario guarda su autor, sus supuestos y la fecha de la dotación de base. Cada cambio queda en auditoría.
 */
final class HeadcountScenarioService
{
    /** Cambios que admite una línea copiada de una posición real y una plaza nueva. */
    private const BASELINE_CHANGES = ['baseline', 'freeze', 'remove'];

    private const ADDED_CHANGES = ['add', 'remove'];

    public function __construct(
        private readonly HeadcountScenarioStateMachine $machine,
        private readonly HeadcountService $headcount,
        private readonly PositionRequestService $requests,
        private readonly PositionService $positions,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, string>|null  $allowedEntities  null = todas
     */
    public function create(array $input, ?array $allowedEntities, string $userId): HeadcountScenario
    {
        $entity = isset($input['legal_entity_id']) ? (string) $input['legal_entity_id'] : null;
        if ($allowedEntities !== null && ($entity === null || ! in_array($entity, $allowedEntities, true))) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.scenarios.entity_required')]]);
        }
        $base = EffectiveDated::day($input['base_as_of'] ?? now());

        return DB::transaction(function () use ($input, $entity, $base, $allowedEntities, $userId): HeadcountScenario {
            $scenario = HeadcountScenario::create([
                'headcount_plan_id' => $this->planFor((int) substr($base, 0, 4), $entity),
                'name' => trim((string) $input['name']),
                'kind' => (string) $input['kind'],
                'is_baseline' => $input['kind'] === 'current',
                'assumptions' => isset($input['assumptions']) && is_array($input['assumptions']) ? $input['assumptions'] : null,
                'legal_entity_id' => $entity,
                'base_as_of' => $base,
                'status' => 'draft',
                'created_by_user_id' => $userId,
            ]);
            $this->copyBaseline($scenario, $entity !== null ? [$entity] : $allowedEntities);
            $scenario->refresh();
            $this->audit->record('headcount_scenario.created', 'headcount_scenario', $scenario->id, null, $this->auditable($scenario));

            return $scenario;
        });
    }

    /**
     * Nombre y supuestos (solo en borrador).
     *
     * @param  array<string, mixed>  $input
     */
    public function update(HeadcountScenario $scenario, array $input, ?int $expectedVersion): HeadcountScenario
    {
        if ($expectedVersion !== null && $expectedVersion !== $scenario->lock_version) {
            throw new VersionConflictException($expectedVersion, $scenario->lock_version);
        }
        $this->assertDraft($scenario);
        $before = $this->auditable($scenario);
        $values = [];
        if (isset($input['name'])) {
            $values['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('assumptions', $input)) {
            $values['assumptions'] = is_array($input['assumptions']) ? $input['assumptions'] : null;
        }
        if (isset($input['kind'])) {
            $values['kind'] = (string) $input['kind'];
            $values['is_baseline'] = $input['kind'] === 'current';
        }
        $scenario->fill($values)->save();
        $scenario->refresh();
        $this->audit->record('headcount_scenario.updated', 'headcount_scenario', $scenario->id, $before, $this->auditable($scenario));

        return $scenario;
    }

    /**
     * Plaza nueva en el escenario.
     *
     * @param  array<string, mixed>  $input
     */
    public function addLine(HeadcountScenario $scenario, array $input): HeadcountPlanLine
    {
        $this->assertDraft($scenario);
        $unitEntity = DB::table('org_units')->where('id', $input['org_unit_id'])->whereNull('deleted_at')->value('legal_entity_id');
        if ($scenario->legal_entity_id !== null && $unitEntity !== null && (string) $unitEntity !== $scenario->legal_entity_id) {
            throw ValidationException::withMessages(['org_unit_id' => [(string) trans('organization.scenarios.unit_other_entity')]]);
        }
        $cost = $input['projected_cost'] ?? null;
        $line = HeadcountPlanLine::create([
            'headcount_scenario_id' => $scenario->id, 'change' => 'add',
            'org_unit_id' => (string) $input['org_unit_id'], 'job_profile_id' => (string) $input['job_profile_id'],
            'site_id' => $input['site_id'] ?? null, 'planned_count' => (int) ($input['planned_count'] ?? 1),
            'planned_start_on' => $input['planned_start_on'] ?? null,
            'title' => isset($input['title']) ? trim((string) $input['title']) : null,
            'projected_cost' => is_array($cost) ? (string) $cost['amount'] : null,
            'currency' => is_array($cost) ? strtoupper((string) $cost['currency']) : null,
            'notes' => $input['notes'] ?? null,
        ]);
        $this->audit->record('headcount_scenario.line_added', 'headcount_scenario', $scenario->id, null, ['line_id' => $line->id, 'org_unit_id' => $line->org_unit_id, 'planned_count' => $line->planned_count]);

        return $line;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateLine(HeadcountPlanLine $line, array $input): HeadcountPlanLine
    {
        $scenario = HeadcountScenario::query()->findOrFail($line->headcount_scenario_id);
        $this->assertDraft($scenario);
        $values = [];
        if (isset($input['change'])) {
            $change = (string) $input['change'];
            $allowed = $line->position_id === null ? self::ADDED_CHANGES : self::BASELINE_CHANGES;
            if (! in_array($change, $allowed, true)) {
                throw ValidationException::withMessages(['change' => [(string) trans('organization.scenarios.change_not_allowed')]]);
            }
            $values['change'] = $change;
        }
        foreach (['planned_count', 'planned_start_on', 'notes', 'title'] as $f) {
            if (array_key_exists($f, $input)) {
                $values[$f] = $input[$f];
            }
        }
        if ($line->position_id !== null && isset($values['planned_count'])) {
            // Una posición real conserva sus cupos: para cambiarlos se congela o se quita y se agrega otra plaza.
            unset($values['planned_count']);
        }
        if (array_key_exists('projected_cost', $input)) {
            $cost = $input['projected_cost'];
            $values['projected_cost'] = is_array($cost) ? (string) $cost['amount'] : null;
            $values['currency'] = is_array($cost) ? strtoupper((string) $cost['currency']) : null;
        }
        $before = ['change' => $line->change, 'planned_count' => $line->planned_count];
        $line->fill($values)->save();
        $this->audit->record('headcount_scenario.line_updated', 'headcount_scenario', $scenario->id, $before + ['line_id' => $line->id], ['line_id' => $line->id, 'change' => $line->change, 'planned_count' => $line->planned_count]);

        return $line;
    }

    public function transition(HeadcountScenario $scenario, string $action, ?string $reason, string $userId): HeadcountScenario
    {
        $next = $this->machine->getNextState($scenario->status, $action);
        if ($action === 'return' && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => [(string) trans('organization.scenarios.reason_required')]]);
        }

        return DB::transaction(function () use ($scenario, $action, $next, $reason, $userId): HeadcountScenario {
            $locked = HeadcountScenario::query()->whereKey($scenario->id)->lockForUpdate()->firstOrFail();
            $this->machine->getNextState($locked->status, $action);
            $before = $this->auditable($locked);
            $values = ['status' => $next];
            if ($action === 'approve') {
                $values += ['approved_by_user_id' => $userId, 'approved_at' => now()];
            }
            if ($action === 'return') {
                $values += ['approved_by_user_id' => null, 'approved_at' => null];
            }
            if ($action === 'apply') {
                $values += ['applied_at' => now(), 'applied_summary' => $this->apply($locked, $userId)];
            }
            $locked->forceFill($values)->save();
            $locked->refresh();
            $this->audit->record('headcount_scenario.'.$action, 'headcount_scenario', $locked->id, $before, $this->auditable($locked), $reason);

            return $locked;
        });
    }

    /**
     * Totales del escenario: plazas planificadas, activas, ocupadas, vacantes, congeladas, agregadas y quitadas, por
     * escenario y por unidad, y el costo mensual proyectado por moneda de las plazas activas (no las congeladas).
     *
     * @return array{totals: array<string, mixed>, units: list<array<string, mixed>>, cost: array{amount: string, currency: string}|null, costs: list<array{amount: string, currency: string}>}
     */
    public function totals(HeadcountScenario $scenario): array
    {
        $lines = HeadcountPlanLine::query()->where('headcount_scenario_id', $scenario->id)->get();
        $status = Position::query()->whereIn('id', $lines->pluck('position_id')->filter()->all())->pluck('status', 'id');
        $names = DB::table('org_units')->whereIn('id', $lines->pluck('org_unit_id')->unique()->all())->pluck('name', 'id');
        $empty = ['planned' => 0, 'active' => 0, 'occupied' => 0, 'vacant' => 0, 'frozen' => 0, 'added' => 0, 'removed' => 0];
        $totals = $empty;
        $units = [];
        $costs = [];
        foreach ($lines as $line) {
            $u = $line->org_unit_id;
            $units[$u] ??= ['org_unit_id' => $u, 'org_unit_name' => (string) ($names[$u] ?? '')] + $empty;
            $n = $line->planned_count;
            $real = $line->position_id === null ? null : (string) ($status[$line->position_id] ?? '');
            $frozen = $line->change === 'freeze' || ($line->change === 'baseline' && $real === 'frozen');
            $delta = ['planned' => 0, 'active' => 0, 'occupied' => 0, 'vacant' => 0, 'frozen' => 0, 'added' => 0, 'removed' => 0];
            if ($line->change === 'remove') {
                $delta['removed'] = $n;
            } else {
                $delta['planned'] = $n;
                if ($frozen) {
                    $delta['frozen'] = $n;
                } else {
                    $delta['active'] = $n;
                    $occupied = $real === 'filled' ? $n : 0;
                    $delta['occupied'] = $occupied;
                    $delta['vacant'] = $n - $occupied;
                    if ($line->projected_cost !== null && $line->currency !== null) {
                        $amount = BigDecimal::of($line->projected_cost)->multipliedBy($line->position_id === null ? $n : 1);
                        $costs[$line->currency] = isset($costs[$line->currency]) ? $costs[$line->currency]->plus($amount) : $amount;
                    }
                }
                if ($line->change === 'add') {
                    $delta['added'] = $n;
                }
            }
            foreach ($delta as $k => $v) {
                $totals[$k] += $v;
                $units[$u][$k] += $v;
            }
        }
        $list = [];
        ksort($costs);
        foreach ($costs as $currency => $amount) {
            $list[] = ['amount' => (string) $amount->toScale(2, RoundingMode::HALF_UP), 'currency' => (string) $currency];
        }
        $default = (string) ($this->parameters->value('org.moneda_defecto') ?? 'PEN');
        $main = null;
        foreach ($list as $c) {
            if ($c['currency'] === $default) {
                $main = $c;
            }
        }
        $main ??= $list[0] ?? null;
        usort($units, fn (array $a, array $b): int => strcmp((string) $a['org_unit_name'], (string) $b['org_unit_name']));

        return ['totals' => $totals, 'units' => $units, 'cost' => $main, 'costs' => $list];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lines(HeadcountScenario $scenario, bool $withCost): array
    {
        $lines = HeadcountPlanLine::query()->where('headcount_scenario_id', $scenario->id)->orderBy('created_at')->orderBy('id')->get();
        $units = DB::table('org_units')->whereIn('id', $lines->pluck('org_unit_id')->unique()->all())->pluck('name', 'id');
        $profiles = DB::table('job_profiles')->whereIn('id', $lines->pluck('job_profile_id')->unique()->all())->pluck('name', 'id');
        $positions = Position::query()->whereIn('id', $lines->pluck('position_id')->filter()->all())->get(['id', 'code', 'status'])->keyBy('id');
        $out = [];
        foreach ($lines as $l) {
            $p = $l->position_id === null ? null : $positions->get($l->position_id);
            $row = [
                'id' => $l->id, 'change' => $l->change, 'position_id' => $l->position_id,
                'position_code' => $p instanceof Position ? $p->code : null, 'position_status' => $p instanceof Position ? $p->status : null,
                'title' => $l->title, 'org_unit_id' => $l->org_unit_id, 'org_unit_name' => (string) ($units[$l->org_unit_id] ?? ''),
                'job_profile_id' => $l->job_profile_id, 'job_profile_name' => (string) ($profiles[$l->job_profile_id] ?? ''),
                'site_id' => $l->site_id, 'planned_count' => $l->planned_count,
                'planned_start_on' => $l->planned_start_on?->toDateString(), 'notes' => $l->notes,
                'position_request_id' => $l->position_request_id,
            ];
            if ($withCost) {
                $row['projected_cost'] = $l->projected_cost === null || $l->currency === null ? null : ['amount' => (string) BigDecimal::of($l->projected_cost)->toScale(2), 'currency' => $l->currency];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function allowedActions(HeadcountScenario $scenario): array
    {
        return array_values($this->machine->allowedActions($scenario->status));
    }

    /**
     * Aplica el escenario: cada cambio va en su propio punto de guardado para que uno que no procede no detenga al resto.
     *
     * @return array<string, mixed>
     */
    private function apply(HeadcountScenario $scenario, string $userId): array
    {
        $summary = ['position_requests' => [], 'frozen' => [], 'closed' => [], 'skipped' => []];
        $reason = (string) trans('organization.scenarios.applied_reason', ['name' => $scenario->name]);
        $lines = HeadcountPlanLine::query()->where('headcount_scenario_id', $scenario->id)->whereIn('change', ['add', 'freeze', 'remove'])->get();
        foreach ($lines as $line) {
            try {
                DB::transaction(function () use ($line, $reason, $userId, &$summary): void {
                    if ($line->change === 'add') {
                        $request = $this->requests->create([
                            'org_unit_id' => $line->org_unit_id, 'job_profile_id' => $line->job_profile_id, 'site_id' => $line->site_id,
                            'title' => $line->title, 'quantity' => $line->planned_count, 'justification' => $reason,
                            'needed_by' => $line->planned_start_on?->toDateString(),
                            'estimated_cost' => $line->projected_cost !== null && $line->currency !== null ? ['amount' => $line->projected_cost, 'currency' => $line->currency] : null,
                        ], $userId);
                        $line->forceFill(['position_request_id' => $request->id])->save();
                        $summary['position_requests'][] = $request->id;

                        return;
                    }
                    if ($line->position_id === null) {
                        return;
                    }
                    $position = Position::query()->findOrFail($line->position_id);
                    $action = $line->change === 'freeze' ? 'freeze' : 'close';
                    $this->positions->transition($position, $action, $reason, null, $userId);
                    $summary[$action === 'freeze' ? 'frozen' : 'closed'][] = $position->id;
                });
            } catch (Throwable $e) {
                if (! $e instanceof AyniException && ! $e instanceof ValidationException) {
                    throw $e;
                }
                $message = $e instanceof ValidationException ? implode(' ', array_map('strval', array_merge(...array_values($e->errors())))) : $e->getMessage();
                $summary['skipped'][] = ['line_id' => $line->id, 'position_id' => $line->position_id, 'change' => $line->change, 'reason' => $message];
            }
        }

        return $summary;
    }

    /**
     * @param  array<int, string>|null  $entities  null = todas
     */
    private function copyBaseline(HeadcountScenario $scenario, ?array $entities): void
    {
        $date = $scenario->base_as_of->toDateString();
        $query = Position::query()->whereIn('status', HeadcountService::COUNTED)
            ->where('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date));
        if ($entities !== null) {
            $query->whereIn('legal_entity_id', $entities);
        }
        $positions = $query->orderBy('code')->get()->all();
        $costs = $this->headcount->projectedCosts($positions, $date);
        $rows = [];
        foreach ($positions as $p) {
            $c = $costs[$p->id] ?? null;
            $rows[] = [
                'id' => (string) Str::uuid(), 'tenant_id' => TenancyScope::requireTenant(), 'headcount_scenario_id' => $scenario->id,
                'org_unit_id' => $p->org_unit_id, 'job_profile_id' => $p->job_profile_id, 'site_id' => $p->site_id,
                'planned_count' => (int) $p->seats, 'position_id' => $p->id, 'change' => 'baseline', 'title' => $p->title,
                'projected_cost' => $c['amount'] ?? null, 'currency' => $c['currency'] ?? null,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('headcount_plan_lines')->insert($chunk);
        }
    }

    private function planFor(int $year, ?string $entity): string
    {
        $id = DB::table('headcount_plans')->whereNull('deleted_at')->where('fiscal_year', $year)
            ->where(fn ($q) => $entity === null ? $q->whereNull('legal_entity_id') : $q->where('legal_entity_id', $entity))->value('id');
        if ($id !== null) {
            return (string) $id;
        }
        $id = (string) Str::uuid();
        DB::table('headcount_plans')->insert([
            'id' => $id, 'tenant_id' => TenancyScope::requireTenant(), 'fiscal_year' => $year, 'legal_entity_id' => $entity,
            'name' => (string) trans('organization.scenarios.plan_name', ['year' => $year]),
        ]);

        return $id;
    }

    private function assertDraft(HeadcountScenario $scenario): void
    {
        if ($scenario->status !== 'draft') {
            throw new AyniException('scenario_not_editable', 409, (string) trans('organization.scenarios.not_editable'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(HeadcountScenario $s): array
    {
        return ['name' => $s->name, 'kind' => $s->kind, 'status' => $s->status, 'legal_entity_id' => $s->legal_entity_id,
            'base_as_of' => $s->base_as_of->toDateString(), 'assumptions' => $s->assumptions];
    }
}
