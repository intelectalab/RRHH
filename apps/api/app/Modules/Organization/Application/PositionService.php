<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\PositionStateMachine;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\PositionStatusEvent;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * POS-1 · POS-2 · Posiciones como plazas (C-02: puesto ≠ posición).
 *
 * - Cada posición es una plaza con puesto de catálogo, empresa, unidad, sede, centro de costo, posición
 *   jefe, banda de referencia y vigencia; muchas posiciones comparten el mismo puesto.
 * - La línea de reporte se deriva de la posición jefe y, si no se indica, del responsable de la unidad
 *   (o de la unidad superior más cercana con responsable) de ORG-3.
 * - Los estados cambian solo por acciones (ME-03). Ocupada / vacante lo decide el sistema con las
 *   asignaciones vigentes (PositionOccupancyService); cada transición deja historial, auditoría y evento.
 * - Puesto, unidad, sede y centro de costo se validan con StructureAvailability.
 */
final class PositionService
{
    /** Campos editables: nombre de la API => columna. */
    public const FIELDS = [
        'code' => 'code', 'title' => 'title', 'job_profile_id' => 'job_profile_id', 'legal_entity_id' => 'legal_entity_id',
        'org_unit_id' => 'org_unit_id', 'site_id' => 'site_id', 'cost_center_id' => 'cost_center_id',
        'reports_to_position_id' => 'reports_to_position_id', 'salary_band_id' => 'salary_band_id',
        'is_critical' => 'is_critical', 'fte' => 'fte', 'is_shared' => 'is_shared', 'seats' => 'seats',
        'valid_from' => 'valid_from', 'valid_to' => 'valid_to',
    ];

    /** Estados en los que la posición ya no cuenta (no admite cambios ni ocupantes). */
    public const CLOSED = 'closed';

    /** Acción → evento del outbox. */
    private const EVENTS = [
        'approve' => DomainEventTypes::POSITION_APPROVED,
        'reject' => DomainEventTypes::POSITION_CLOSED,
        'close' => DomainEventTypes::POSITION_CLOSED,
        'open-recruitment' => DomainEventTypes::POSITION_OPENED,
        'cancel-recruitment' => DomainEventTypes::POSITION_VACATED,
        'vacate' => DomainEventTypes::POSITION_VACATED,
        'fill' => DomainEventTypes::POSITION_FILLED,
        'freeze' => DomainEventTypes::POSITION_FROZEN,
        'unfreeze' => DomainEventTypes::POSITION_UNFROZEN,
    ];

    /** Máximo de saltos al seguir la línea de reporte (protege de datos cíclicos). */
    private const MAX_LINE = 50;

    /** @var array<string, object{id: string, parent_id: string|null, head_position_id: string|null, name: string}>|null */
    private ?array $units = null;

    public function __construct(
        private readonly PositionStateMachine $machine,
        private readonly StructureAvailability $structure,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly HeadcountService $headcount,
    ) {}

    // ------------------------------------------------------------------ Presentación

    /**
     * @return array<string, mixed>
     */
    public function present(Position $position): array
    {
        return $this->presentMany([$position])[0];
    }

    /**
     * Presenta varias posiciones con sus nombres, ocupantes y responsable derivado en pocas consultas.
     * El costo presupuestado (budget) viaja aquí; SensitiveFields lo quita sin permiso.
     *
     * @param  array<int, Position>  $positions
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(array $positions): array
    {
        if ($positions === []) {
            return [];
        }
        $this->units = null;
        $ids = array_map(fn (Position $p) => $p->id, $positions);
        $names = fn (string $table, string $column, string $field) => DB::table($table)
            ->whereIn('id', array_values(array_unique(array_filter(array_map(fn (Position $p) => $p->getAttribute($field), $positions)))))
            ->pluck($column, 'id')->map(fn ($v) => (string) $v)->all();
        $profiles = $names('job_profiles', 'name', 'job_profile_id');
        $entities = $names('legal_entities', 'legal_name', 'legal_entity_id');
        $unitNames = $names('org_units', 'name', 'org_unit_id');
        $sites = $names('sites', 'name', 'site_id');
        $centers = $names('cost_centers', 'name', 'cost_center_id');
        $bosses = $names('positions', 'title', 'reports_to_position_id');
        $occupants = $this->occupantsOf($ids, EffectiveDated::day(now()));
        $heads = DB::table('org_units')->whereIn('head_position_id', $ids)->whereNull('deleted_at')->where('status', 'active')
            ->orderBy('name')->get(['id', 'name', 'head_position_id']);

        $out = [];
        foreach ($positions as $p) {
            $current = $occupants[$p->id] ?? [];
            $effective = $this->effectiveManagerId($p);
            $row = [
                'id' => $p->id,
                'code' => $p->code,
                'title' => $p->title,
                'job_profile_id' => $p->job_profile_id,
                'job_profile_name' => $profiles[$p->job_profile_id] ?? null,
                'legal_entity_id' => $p->legal_entity_id,
                'legal_entity_name' => $entities[$p->legal_entity_id] ?? null,
                'org_unit_id' => $p->org_unit_id,
                'org_unit_name' => $unitNames[$p->org_unit_id] ?? null,
                'site_id' => $p->site_id,
                'site_name' => $p->site_id === null ? null : ($sites[$p->site_id] ?? null),
                'cost_center_id' => $p->cost_center_id,
                'cost_center_name' => $p->cost_center_id === null ? null : ($centers[$p->cost_center_id] ?? null),
                'reports_to_position_id' => $p->reports_to_position_id,
                'reports_to_title' => $p->reports_to_position_id === null ? null : ($bosses[$p->reports_to_position_id] ?? null),
                'effective_reports_to_position_id' => $effective,
                'salary_band_id' => $p->salary_band_id,
                'is_critical' => $p->is_critical,
                'fte' => (float) $p->fte,
                'is_shared' => $p->is_shared,
                'seats' => $p->seats,
                'occupied_seats' => count($current),
                'occupants' => $current,
                'heads_units' => $heads->where('head_position_id', $p->id)->map(fn ($u) => ['id' => (string) $u->id, 'name' => (string) $u->name])->values()->all(),
                'valid_from' => $p->valid_from->toDateString(),
                'valid_to' => $p->valid_to?->toDateString(),
                'status' => $p->status,
                'position_request_id' => $p->position_request_id,
                'budget' => $p->budgeted_cost === null || $p->currency === null ? null
                    : ['amount' => (string) $p->budgeted_cost, 'currency' => (string) $p->currency],
                'allowed_actions' => $this->machine->manualActions($p->status),
                'version' => $p->lock_version,
                'created_at' => $p->created_at->toIso8601String(),
                'updated_at' => $p->updated_at->toIso8601String(),
            ];
            if ($row['budget'] === null) {
                // Sin costo registrado el campo no viaja (Money no admite null).
                unset($row['budget']);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentEvent(PositionStatusEvent $event): array
    {
        return [
            'id' => $event->id,
            'action' => $event->action,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'reason' => $event->reason,
            'effective_on' => $event->effective_on->toDateString(),
            'changed_by_user_id' => $event->changed_by_user_id,
            'changed_by_name' => $event->changed_by_user_id === null ? null
                : DB::table('users')->where('id', $event->changed_by_user_id)->value('display_name'),
            'created_at' => $event->created_at->toIso8601String(),
        ];
    }

    /**
     * Ocupantes de las posiciones a una fecha (asignaciones vigentes), por posición.
     *
     * @param  array<int, string>  $positionIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function occupantsOf(array $positionIds, string $date): array
    {
        if ($positionIds === []) {
            return [];
        }
        $rows = $this->assignmentQuery()->whereIn('a.position_id', $positionIds)
            ->where('a.valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $date))
            ->orderBy('a.valid_from')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->position_id][] = $this->presentOccupant($r);
        }

        return $out;
    }

    /**
     * Historia de ocupantes de una posición, del más reciente al más antiguo (POS-1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function occupantHistory(Position $position, ?string $asOf = null): array
    {
        $q = $this->assignmentQuery()->where('a.position_id', $position->id);
        if ($asOf !== null) {
            $q->where('a.valid_from', '<=', $asOf)->where(fn ($w) => $w->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $asOf));
        }

        return $q->orderByDesc('a.valid_from')->get()->map(fn ($r) => $this->presentOccupant($r))->values()->all();
    }

    /**
     * Línea de reporte derivada: posición jefe explícita o responsable de la unidad (ORG-3), hacia arriba.
     *
     * @return array<int, array<string, mixed>>
     */
    public function reportingLine(Position $position): array
    {
        $this->units = null;
        $line = [];
        $seen = [$position->id => true];
        $current = $position;
        $today = EffectiveDated::day(now());
        while (count($line) < self::MAX_LINE) {
            $source = $current->reports_to_position_id !== null ? 'position' : 'unit_head';
            $next = $this->effectiveManagerId($current);
            if ($next === null || isset($seen[$next])) {
                break;
            }
            $seen[$next] = true;
            $boss = Position::query()->find($next);
            if (! $boss instanceof Position) {
                break;
            }
            $line[] = [
                'position_id' => $boss->id,
                'code' => $boss->code,
                'title' => $boss->title,
                'org_unit_id' => $boss->org_unit_id,
                'org_unit_name' => $this->unitMap()[$boss->org_unit_id]->name ?? null,
                'status' => $boss->status,
                'source' => $source,
                'occupants' => $this->occupantsOf([$boss->id], $today)[$boss->id] ?? [],
            ];
            $current = $boss;
        }

        return $line;
    }

    /**
     * Jefe derivado: la posición jefe indicada o, si no hay, el responsable de su unidad (o de la unidad
     * superior más cercana con responsable) siempre que no sea la propia posición.
     */
    public function effectiveManagerId(Position $position): ?string
    {
        if ($position->reports_to_position_id !== null) {
            return $position->reports_to_position_id;
        }
        $units = $this->unitMap();
        $unitId = $position->org_unit_id;
        $guard = 0;
        while ($unitId !== null && isset($units[$unitId]) && $guard++ < self::MAX_LINE) {
            $head = $units[$unitId]->head_position_id;
            if ($head !== null && $head !== $position->id) {
                return $head;
            }
            $unitId = $units[$unitId]->parent_id;
        }

        return null;
    }

    // ------------------------------------------------------------------ Escritura

    /**
     * @param  array<string, mixed>  $input  ya validado (claves de la API)
     */
    public function create(array $input, ?string $userId): Position
    {
        $values = $this->columns($input);
        $values['is_shared'] ??= false;
        $values['seats'] ??= 1;
        $this->check($values, null);

        return DB::transaction(function () use ($values, $userId): Position {
            $position = Position::create($values + ['status' => 'proposed']);
            $position->refresh();
            $this->recordEvent($position, 'create', null, 'proposed', null, $userId, $position->valid_from->toDateString());
            $this->audit->record('position.created', 'position', $position->id, null, $this->auditable($position));

            return $position;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Position $position, array $input, ?int $expectedVersion): Position
    {
        if ($expectedVersion !== null && $expectedVersion !== $position->lock_version) {
            throw new VersionConflictException($expectedVersion, $position->lock_version);
        }
        if ($position->status === self::CLOSED) {
            throw new AyniException('invalid_transition', 409, (string) trans('organization.positions.closed_readonly'), [], ['current_status' => 'closed', 'allowed_actions' => []]);
        }
        $values = $this->columns($input);
        $this->check($values, $position);

        return DB::transaction(function () use ($position, $values): Position {
            $before = $this->auditable($position);
            $position->fill($values);
            if ($position->isDirty()) {
                $position->save();
            }
            $position->refresh();
            $this->audit->record('position.updated', 'position', $position->id, $before, $this->auditable($position));

            return $position;
        });
    }

    /**
     * Acción explícita sobre la posición (ME-03). Las del sistema (fill / vacate) las pide la ocupación.
     */
    public function transition(Position $position, string $action, ?string $reason, ?string $effectiveOn, ?string $userId): Position
    {
        $next = $this->machine->getNextState($position->status, $action);
        $day = EffectiveDated::day($effectiveOn ?? now());
        if (in_array($action, ['reject', 'freeze', 'close'], true) && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => [(string) trans('organization.positions.reason_required')]]);
        }
        if ($action === 'close') {
            $this->assertClosable($position);
        }
        if ($action === 'unfreeze' || $action === 'approve') {
            // Volver a admitir ocupantes exige que la estructura siga disponible.
            $this->structure->assertJobProfileAssignable($position->job_profile_id);
            $this->structure->assertOrgUnitAssignable($position->org_unit_id);
        }
        if ($action === 'approve') {
            $reason = $this->assertBudget($position, $reason);
        }

        return DB::transaction(function () use ($position, $action, $next, $reason, $day, $userId): Position {
            $locked = Position::query()->whereKey($position->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $this->machine->getNextState($from, $action);
            $before = $this->auditable($locked);
            $locked->forceFill(['status' => $next])->save();
            $locked->refresh();
            $this->recordEvent($locked, $action, $from, $next, $reason, $userId, $day);
            $this->outbox->record(self::EVENTS[$action], 'position', $locked->id, [
                'position_id' => $locked->id, 'from_status' => $from, 'to_status' => $next, 'reason' => $reason,
                'occupied_seats' => $this->countOccupants($locked->id, EffectiveDated::day(now())), 'effective_on' => $day,
            ]);
            $this->audit->record('position.'.str_replace('-', '_', $action), 'position', $locked->id, $before, $this->auditable($locked), $reason);

            return $locked;
        });
    }

    /**
     * POS-3 · Crea una posición ya aprobada por el flujo de su solicitud (sin pasar por «propuesta»):
     * la aprobación del flujo es la decisión, incluso si superaba el presupuesto (queda en el motivo).
     *
     * @param  array<string, mixed>  $values  columnas
     */
    public function createApproved(array $values, string $reason): Position
    {
        $position = Position::create($values + ['status' => 'approved', 'is_shared' => false, 'seats' => 1]);
        $position->refresh();
        $day = $position->valid_from->toDateString();
        $this->recordEvent($position, 'approve', null, 'approved', $reason, null, $day);
        $this->outbox->record(self::EVENTS['approve'], 'position', $position->id, [
            'position_id' => $position->id, 'from_status' => null, 'to_status' => 'approved', 'reason' => $reason,
            'occupied_seats' => 0, 'effective_on' => $day, 'position_request_id' => $position->position_request_id,
        ]);
        $this->audit->record('position.created', 'position', $position->id, null, $this->auditable($position), $reason);

        return $position;
    }

    /**
     * POS-4 · Aprobar una plaza que excede el presupuesto de su unidad exige una excepción con motivo.
     * Devuelve el motivo a registrar (marcado como excepción de presupuesto).
     */
    private function assertBudget(Position $position, ?string $reason): ?string
    {
        $day = max($position->valid_from->toDateString(), EffectiveDated::day(now()));
        $cost = $this->headcount->positionCost($position, $day);
        if (! $this->headcount->exceeds($position->org_unit_id, $day, $position->seats, $cost, $position->id)) {
            return $reason;
        }
        if ($reason === null || trim($reason) === '') {
            throw new AyniException('business_rule_violated', 422, (string) trans('organization.positions.over_budget_reason_required'), [], ['rule' => 'over_budget']);
        }

        return (string) trans('organization.positions.over_budget_exception', ['reason' => trim($reason)]);
    }

    public function countOccupants(string $positionId, string $date, ?string $exceptEmploymentId = null, ?string $to = null): int
    {
        return DB::table('employment_assignments')->where('position_id', $positionId)
            ->when($exceptEmploymentId !== null, fn ($q) => $q->where('employment_id', '<>', $exceptEmploymentId))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->when($to !== null, fn ($q) => $q->where('valid_from', '<=', $to), fn ($q) => $q->where('valid_from', '<=', $date))
            ->count();
    }

    // ------------------------------------------------------------------ Reglas

    /** RN-POS-06: no se cierra con ocupantes; tampoco si es responsable de una unidad o jefe directo de otras. */
    private function assertClosable(Position $position): void
    {
        $today = EffectiveDated::day(now());
        if ($this->countOccupants($position->id, $today) > 0) {
            throw new AyniException('business_rule_violated', 422, (string) trans('organization.positions.occupied_cannot_close'));
        }
        $units = DB::table('org_units')->where('head_position_id', $position->id)->whereNull('deleted_at')->where('status', 'active')->count();
        if ($units > 0) {
            throw new AyniException('business_rule_violated', 422, (string) trans('organization.positions.unit_head_cannot_close', ['count' => $units]));
        }
        $reports = Position::query()->where('reports_to_position_id', $position->id)->where('status', '<>', self::CLOSED)->count();
        if ($reports > 0) {
            throw new AyniException('business_rule_violated', 422, (string) trans('organization.positions.has_reports_cannot_close', ['count' => $reports]));
        }
    }

    /**
     * Reglas que dependen de otros datos (las de forma están en el controlador). En una edición solo se
     * valida lo que cambia: una posición conserva su sede aunque luego se archive.
     *
     * @param  array<string, mixed>  $v  columnas
     */
    private function check(array $v, ?Position $position): void
    {
        $get = fn (string $c) => array_key_exists($c, $v) ? $v[$c] : $position?->getAttribute($c);
        $changed = fn (string $c) => array_key_exists($c, $v) && (! $position instanceof Position || $position->getAttribute($c) !== $v[$c]);
        $errors = [];

        $code = $v['code'] ?? null;
        if (is_string($code) && Position::query()->withTrashed()->whereRaw('lower(code) = lower(?)', [$code])
            ->when($position instanceof Position, fn ($q) => $q->whereKeyNot($position?->id))->exists()) {
            $errors['code'] = [(string) trans('organization.positions.duplicate_code')];
        }
        $entity = $get('legal_entity_id');
        $validFrom = $get('valid_from');
        $from = $validFrom instanceof \DateTimeInterface ? $validFrom->format('Y-m-d') : (is_string($validFrom) ? $validFrom : EffectiveDated::day(now()));
        $validTo = $get('valid_to');
        $to = $validTo instanceof \DateTimeInterface ? $validTo->format('Y-m-d') : (is_string($validTo) ? $validTo : null);
        if ($to !== null && $to < $from) {
            $errors['valid_to'] = [(string) trans('organization.positions.valid_to_before')];
        }

        $collect = function (callable $assert) use (&$errors): void {
            try {
                $assert();
            } catch (ValidationException $e) {
                $errors = array_merge($errors, $e->errors());
            }
        };
        if ($changed('legal_entity_id') && is_string($entity)) {
            $collect(fn () => $this->structure->assertLegalEntityAssignable($entity));
        }
        if ($changed('job_profile_id') && is_string($v['job_profile_id'])) {
            $collect(fn () => $this->structure->assertJobProfileAssignable((string) $v['job_profile_id']));
        }
        $unit = $get('org_unit_id');
        if ($changed('org_unit_id') && is_string($unit)) {
            $collect(fn () => $this->structure->assertOrgUnitAssignable($unit));
        }
        if (($changed('org_unit_id') || $changed('legal_entity_id')) && is_string($unit) && is_string($entity)) {
            $unitEntity = DB::table('org_units')->where('id', $unit)->value('legal_entity_id');
            if ($unitEntity !== null && $unitEntity !== $entity) {
                $errors['org_unit_id'] = [(string) trans('organization.positions.unit_other_company')];
            }
        }
        $site = $get('site_id');
        if (($changed('site_id') || ($changed('legal_entity_id') && $site !== null)) && is_string($site)) {
            $collect(fn () => $this->structure->assertSiteAssignable($site, is_string($entity) ? $entity : null));
        }
        $center = $get('cost_center_id');
        if (($changed('cost_center_id') || ($changed('legal_entity_id') && $center !== null)) && is_string($center)) {
            $collect(fn () => $this->structure->assertCostCenterAssignable($center, $from, is_string($entity) ? $entity : null));
        }
        if ($changed('salary_band_id') && is_string($v['salary_band_id'])
            && ! DB::table('salary_bands')->where('id', $v['salary_band_id'])->whereNull('deleted_at')->exists()) {
            $errors['salary_band_id'] = [(string) trans('organization.positions.unknown_band')];
        }
        $boss = $get('reports_to_position_id');
        if ($changed('reports_to_position_id') && is_string($boss)) {
            $problem = $this->bossProblem($boss, $position?->id);
            if ($problem !== null) {
                $errors['reports_to_position_id'] = [(string) trans('organization.positions.'.$problem)];
            }
        }

        // RN-POS-02: compartida con cupos; si no, un solo ocupante. No se bajan cupos por debajo de los ocupados.
        $shared = (bool) $get('is_shared');
        $seats = (int) ($get('seats') ?? 1);
        if (! $shared && $seats !== 1) {
            $errors['seats'] = [(string) trans('organization.positions.seats_need_shared')];
        }
        if ($position instanceof Position && ($changed('seats') || $changed('is_shared'))) {
            $occupied = $this->countOccupants($position->id, EffectiveDated::day(now()));
            if ($seats < $occupied) {
                $errors['seats'] = [(string) trans('organization.positions.seats_below_occupied', ['count' => $occupied])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** La posición jefe existe, no está cerrada, no es la misma y no crea un ciclo en la línea de reporte. */
    private function bossProblem(string $bossId, ?string $selfId): ?string
    {
        if ($selfId !== null && $bossId === $selfId) {
            return 'reports_to_self';
        }
        $boss = Position::query()->find($bossId);
        if (! $boss instanceof Position || $boss->status === self::CLOSED) {
            return 'unknown_boss';
        }
        if ($selfId === null) {
            return null;
        }
        $seen = [];
        $current = $boss;
        while ($current->reports_to_position_id !== null && count($seen) < self::MAX_LINE) {
            if ($current->reports_to_position_id === $selfId) {
                return 'reports_to_cycle';
            }
            $seen[] = $current->id;
            $nextBoss = Position::query()->find($current->reports_to_position_id);
            if (! $nextBoss instanceof Position || in_array($nextBoss->id, $seen, true)) {
                break;
            }
            $current = $nextBoss;
        }

        return null;
    }

    private function recordEvent(Position $position, string $action, ?string $from, string $to, ?string $reason, ?string $userId, string $day): void
    {
        PositionStatusEvent::create([
            'position_id' => $position->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'changed_by_user_id' => $userId,
            'effective_on' => $day,
        ]);
    }

    /**
     * @return Builder
     */
    private function assignmentQuery()
    {
        return DB::table('employment_assignments as a')
            ->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->select(['a.id', 'a.position_id', 'a.employment_id', 'a.valid_from', 'a.valid_to', 'a.reason',
                'e.employee_code', 'p.first_names', 'p.paternal_surname', 'p.maternal_surname']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOccupant(object $r): array
    {
        /** @var object{id: string, employment_id: string, valid_from: string, valid_to: string|null, reason: string|null, employee_code: string, first_names: string, paternal_surname: string|null, maternal_surname: string|null} $r */
        return [
            'assignment_id' => (string) $r->id,
            'employment_id' => (string) $r->employment_id,
            'employee_code' => (string) $r->employee_code,
            'person_name' => trim($r->first_names.' '.($r->paternal_surname ?? '').' '.($r->maternal_surname ?? '')),
            'valid_from' => substr((string) $r->valid_from, 0, 10),
            'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10),
            'reason' => $r->reason,
        ];
    }

    /**
     * @return array<string, object{id: string, parent_id: string|null, head_position_id: string|null, name: string}>
     */
    private function unitMap(): array
    {
        if ($this->units === null) {
            $this->units = [];
            foreach (DB::table('org_units')->whereNull('deleted_at')->get(['id', 'parent_id', 'head_position_id', 'name']) as $u) {
                /** @var object{id: string, parent_id: string|null, head_position_id: string|null, name: string} $u */
                $this->units[(string) $u->id] = $u;
            }
        }

        return $this->units;
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
        if (isset($values['code']) && is_string($values['code'])) {
            $values['code'] = strtoupper(trim($values['code']));
        }
        if (isset($values['title']) && is_string($values['title'])) {
            $values['title'] = trim($values['title']);
        }
        if (array_key_exists('budget', $input)) {
            $budget = $input['budget'];
            $values['budgeted_cost'] = is_array($budget) ? (string) $budget['amount'] : null;
            $values['currency'] = is_array($budget) ? strtoupper((string) $budget['currency']) : null;
        }

        return $values;
    }

    /**
     * Valores para la auditoría (incluye el costo presupuestado: la auditoría ya es de acceso restringido).
     *
     * @return array<string, mixed>
     */
    private function auditable(Position $position): array
    {
        return [
            'code' => $position->code, 'title' => $position->title, 'job_profile_id' => $position->job_profile_id,
            'legal_entity_id' => $position->legal_entity_id, 'org_unit_id' => $position->org_unit_id,
            'site_id' => $position->site_id, 'cost_center_id' => $position->cost_center_id,
            'reports_to_position_id' => $position->reports_to_position_id, 'salary_band_id' => $position->salary_band_id,
            'is_critical' => $position->is_critical, 'fte' => (string) $position->fte, 'is_shared' => $position->is_shared,
            'seats' => $position->seats, 'valid_from' => $position->valid_from->toDateString(),
            'valid_to' => $position->valid_to?->toDateString(), 'status' => $position->status,
            'budgeted_cost' => $position->budgeted_cost, 'currency' => $position->currency,
        ];
    }
}
