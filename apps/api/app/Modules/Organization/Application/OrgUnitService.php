<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\OrgUnitStateMachine;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ORG-3 · Árbol de unidades organizacionales con vigencia (C-03, RN-ORG-01 a 03).
 *
 * La estructura de cada unidad (superior, nombre, tipo, responsable, centro de costo) vive en
 * org_unit_versions: crear, editar o mover desde una fecha cierra la versión vigente e inserta otra
 * (EffectiveDated), así que un reporte a una fecha pasada sigue leyendo la estructura de ese día.
 * org_units guarda además la foto vigente hoy (y su ruta) para el alcance de datos (SEG-10) y las
 * búsquedas; los cambios con fecha futura se aplican a la foto el día que rigen
 * (org:apply-structure-changes).
 */
final class OrgUnitService
{
    /** Columnas de estructura que se versionan. */
    private const STRUCTURE = ['parent_id', 'name', 'org_unit_type_id', 'head_position_id', 'cost_center_id'];

    public function __construct(
        private readonly EffectiveDated $validity,
        private readonly OrgUnitTypeCatalog $types,
        private readonly OrgUnitStateMachine $machine,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  code, name, type_id, parent_id?, legal_entity_id?, head_position_id?, cost_center_id?, effective_from?
     */
    public function create(array $data, ?string $userId): OrgUnit
    {
        $this->types->ensureDefaults();
        $day = $this->day($data['effective_from'] ?? null);
        if (OrgUnit::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('organization.units.code_exists'));
        }
        $this->types->assertUsable((string) $data['type_id']);
        $parentId = $data['parent_id'] ?? null;
        $legalEntity = $data['legal_entity_id'] ?? null;
        if ($parentId !== null) {
            $parent = $this->assertParentUsable((string) $parentId, $day);
            $legalEntity ??= $parent->legal_entity_id;
        }
        $this->assertLegalEntity($legalEntity);
        $this->assertPosition($data['head_position_id'] ?? null);
        $this->assertCostCenter($data['cost_center_id'] ?? null);

        return DB::transaction(function () use ($data, $day, $parentId, $legalEntity, $userId): OrgUnit {
            $structure = [
                'parent_id' => $parentId,
                'name' => (string) $data['name'],
                'org_unit_type_id' => (string) $data['type_id'],
                'head_position_id' => $data['head_position_id'] ?? null,
                'cost_center_id' => $data['cost_center_id'] ?? null,
            ];
            $unit = OrgUnit::query()->create($structure + [
                'code' => (string) $data['code'],
                'legal_entity_id' => $legalEntity,
                'valid_from' => $day,
                'status' => 'active',
            ]);
            $this->validity->change('org_unit_versions', ['tenant_id' => $unit->tenant_id, 'org_unit_id' => $unit->id],
                $structure + ['reason' => $data['reason'] ?? null, 'changed_by_user_id' => $userId], $day);
            $this->refreshPaths();
            $unit->refresh();
            $this->audit->record('org_unit.created', 'org_unit', $unit->id, null, $this->snapshot($unit) + ['effective_from' => $day]);
            $this->outbox->record(DomainEventTypes::ORG_UNIT_CREATED, 'org_unit', $unit->id, [
                'org_unit_id' => $unit->id, 'parent_id' => $parentId, 'effective_on' => $day,
            ]);

            return $unit;
        });
    }

    /**
     * Edición: el código y la empresa son identidad (se corrigen en sitio, auditado); nombre, tipo,
     * responsable y centro de costo se registran como una versión nueva desde effective_from.
     * Cambiar la unidad superior es mover (mismas reglas que la acción move).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(OrgUnit $unit, array $data, ?string $userId): OrgUnit
    {
        $day = $this->day($data['effective_from'] ?? null);
        $this->assertNotBeforeStart($unit, $day);
        if (isset($data['code']) && $data['code'] !== $unit->code && OrgUnit::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('organization.units.code_exists'));
        }
        if (array_key_exists('type_id', $data) && $data['type_id'] !== null) {
            $this->types->assertUsable((string) $data['type_id']);
        }
        if (array_key_exists('legal_entity_id', $data)) {
            $this->assertLegalEntity($data['legal_entity_id']);
        }
        $this->assertPosition($data['head_position_id'] ?? null);
        $this->assertCostCenter($data['cost_center_id'] ?? null);

        $current = $this->versionAt($unit, $day);
        $changes = [];
        foreach (['name' => 'name', 'type_id' => 'org_unit_type_id', 'head_position_id' => 'head_position_id', 'cost_center_id' => 'cost_center_id', 'parent_id' => 'parent_id'] as $in => $col) {
            if (array_key_exists($in, $data) && ($data[$in] ?? null) !== ($current[$col] ?? null)) {
                $changes[$col] = $data[$in];
            }
        }
        if (array_key_exists('parent_id', $changes)) {
            $this->assertMovable($unit, $changes['parent_id'] === null ? null : (string) $changes['parent_id'], $day);
        }

        return DB::transaction(function () use ($unit, $data, $day, $current, $changes, $userId): OrgUnit {
            $before = $this->snapshot($unit);
            $identity = array_intersect_key($data, array_flip(['code', 'legal_entity_id']));
            if ($identity !== []) {
                $unit->fill($identity)->save();
            }
            if ($changes !== []) {
                $this->validity->change('org_unit_versions', ['tenant_id' => $unit->tenant_id, 'org_unit_id' => $unit->id],
                    array_merge($current, $changes, ['reason' => $data['reason'] ?? null, 'changed_by_user_id' => $userId]), $day, EffectiveDated::SAME_DAY_REPLACE);
                $this->syncSnapshot($unit);
            }
            $unit->refresh();
            $this->audit->record('org_unit.updated', 'org_unit', $unit->id, $before, $this->snapshot($unit) + ['effective_from' => $day, 'changes' => array_keys($changes)]);
            $this->emitStructureEvents($unit, $current, $changes, $day);

            return $unit;
        });
    }

    /** Acción move: cambia la unidad superior desde una fecha (RN-ORG-02: sin ciclos). */
    public function move(OrgUnit $unit, ?string $parentId, ?string $effectiveOn, ?string $reason, ?string $userId): OrgUnit
    {
        if ($unit->status !== 'active') {
            throw new AyniException('invalid_transition', 409, (string) trans('organization.units.archived_cannot_move'), [], ['state' => $unit->status, 'action' => 'move']);
        }
        $day = $this->day($effectiveOn);
        $this->assertNotBeforeStart($unit, $day);
        $this->assertMovable($unit, $parentId, $day);
        $current = $this->versionAt($unit, $day);
        if (($current['parent_id'] ?? null) === $parentId) {
            throw new BusinessRuleViolatedException('same_parent', (string) trans('organization.units.same_parent'));
        }

        return DB::transaction(function () use ($unit, $parentId, $day, $reason, $userId, $current): OrgUnit {
            $before = $this->snapshot($unit);
            $this->validity->change('org_unit_versions', ['tenant_id' => $unit->tenant_id, 'org_unit_id' => $unit->id],
                array_merge($current, ['parent_id' => $parentId, 'reason' => $reason, 'changed_by_user_id' => $userId]), $day, EffectiveDated::SAME_DAY_REPLACE);
            $this->syncSnapshot($unit);
            $unit->refresh();
            $this->audit->record('org_unit.moved', 'org_unit', $unit->id, $before + ['parent_id' => $current['parent_id']], $this->snapshot($unit) + ['parent_id' => $parentId, 'effective_on' => $day, 'reason' => $reason]);
            $this->emitStructureEvents($unit, $current, ['parent_id' => $parentId], $day);

            return $unit;
        });
    }

    /** Acción archive: RN-ORG-01, sin posiciones activas ni subunidades. La vida de la unidad termina en effective_on. */
    public function archive(OrgUnit $unit, ?string $effectiveOn, ?string $reason): OrgUnit
    {
        $next = $this->machine->getNextState($unit->status, OrgUnitStateMachine::ARCHIVE);
        $day = $this->day($effectiveOn);
        $this->assertNotBeforeStart($unit, $day);
        if (DB::table('org_unit_versions')->where('org_unit_id', $unit->id)->where('valid_from', '>', $day)->exists()) {
            throw new BusinessRuleViolatedException('scheduled_change_pending', (string) trans('organization.units.scheduled_pending'));
        }
        $impact = $this->impact($unit);
        if ($impact['subunits'] > 0 || $impact['active_positions'] > 0) {
            throw new BusinessRuleViolatedException('org_unit_in_use', (string) trans('organization.units.in_use', [
                'subunits' => $impact['subunits'], 'positions' => $impact['active_positions'],
            ]));
        }

        return DB::transaction(function () use ($unit, $next, $day, $reason): OrgUnit {
            $before = $this->snapshot($unit);
            $this->validity->close('org_unit_versions', ['tenant_id' => $unit->tenant_id, 'org_unit_id' => $unit->id], $day);
            $unit->forceFill(['status' => $next, 'valid_to' => $day])->save();
            $this->audit->record('org_unit.archived', 'org_unit', $unit->id, $before, $this->snapshot($unit->refresh()) + ['reason' => $reason]);
            $this->outbox->record(DomainEventTypes::ORG_UNIT_ARCHIVED, 'org_unit', $unit->id, ['org_unit_id' => $unit->id, 'effective_on' => $day]);

            return $unit;
        });
    }

    /** Acción restore: vuelve a regir desde hoy con su última estructura; su superior debe estar activa. */
    public function restore(OrgUnit $unit, ?string $reason, ?string $userId): OrgUnit
    {
        $next = $this->machine->getNextState($unit->status, OrgUnitStateMachine::RESTORE);
        $day = now()->toDateString();
        $last = $this->lastVersion($unit);
        if ($last['parent_id'] !== null) {
            $this->assertParentUsable((string) $last['parent_id'], $day);
        }

        return DB::transaction(function () use ($unit, $next, $day, $last, $reason, $userId): OrgUnit {
            $before = $this->snapshot($unit);
            $open = DB::table('org_unit_versions')->where('org_unit_id', $unit->id)->orderByDesc('valid_from')->first(['id', 'valid_to']);
            if ($open !== null && ($open->valid_to === null || (string) $open->valid_to >= $day)) {
                // El cierre todavía no había pasado: la versión vigente simplemente sigue rigiendo.
                DB::table('org_unit_versions')->where('id', $open->id)->update(['valid_to' => null]);
            } else {
                $this->validity->change('org_unit_versions', ['tenant_id' => $unit->tenant_id, 'org_unit_id' => $unit->id],
                    array_merge($last, ['reason' => $reason, 'changed_by_user_id' => $userId]), $day);
            }
            $unit->forceFill(['status' => $next, 'valid_to' => null])->save();
            $this->syncSnapshot($unit);
            $this->audit->record('org_unit.restored', 'org_unit', $unit->id, $before, $this->snapshot($unit->refresh()) + ['reason' => $reason]);

            return $unit;
        });
    }

    /**
     * Impacto de mover o archivar (M-ORG flujo «Crear o mover una unidad»): subunidades, posiciones
     * activas y personas asignadas hoy en toda la rama.
     *
     * @return array{subunits: int, descendants: int, active_positions: int, people: int}
     */
    public function impact(OrgUnit $unit): array
    {
        $today = now()->toDateString();
        $branch = $this->descendantIds($unit->id, $today);
        $all = array_merge([$unit->id], $branch);

        return [
            'subunits' => count($this->childrenIds($unit->id, $today)),
            'descendants' => count($branch),
            'active_positions' => DB::table('positions')->whereIn('org_unit_id', $all)->whereNull('deleted_at')->where('status', '!=', 'closed')->count(),
            'people' => DB::table('employment_assignments')->whereIn('org_unit_id', $all)
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
                ->distinct()->count('employment_id'),
        ];
    }

    /**
     * Historia de la estructura de la unidad, de la versión más reciente a la más antigua.
     *
     * @return list<array<string, mixed>>
     */
    public function history(OrgUnit $unit): array
    {
        $rows = DB::table('org_unit_versions as v')
            ->leftJoin('org_units as p', 'p.id', '=', 'v.parent_id')
            ->leftJoin('org_unit_types as t', 't.id', '=', 'v.org_unit_type_id')
            ->leftJoin('positions as hp', 'hp.id', '=', 'v.head_position_id')
            ->leftJoin('cost_centers as cc', 'cc.id', '=', 'v.cost_center_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.changed_by_user_id')
            ->where('v.org_unit_id', $unit->id)
            ->orderByDesc('v.valid_from')
            ->get(['v.id', 'v.valid_from', 'v.valid_to', 'v.name', 'v.parent_id', 'p.name as parent_name', 'v.org_unit_type_id as type_id',
                't.name as type_name', 'v.head_position_id', 'hp.title as head_position_title', 'v.cost_center_id', 'cc.name as cost_center_name',
                'v.reason', 'u.display_name as changed_by', 'v.created_at']);
        $today = now()->toDateString();

        return array_values($rows->map(fn ($r) => [
            'id' => (string) $r->id,
            'valid_from' => (string) $r->valid_from,
            'valid_to' => $r->valid_to === null ? null : (string) $r->valid_to,
            'is_current' => (string) $r->valid_from <= $today && ($r->valid_to === null || (string) $r->valid_to >= $today),
            'is_scheduled' => (string) $r->valid_from > $today,
            'name' => (string) $r->name,
            'parent_id' => $r->parent_id,
            'parent_name' => $r->parent_name,
            'type_id' => $r->type_id,
            'type_name' => $r->type_name,
            'head_position_id' => $r->head_position_id,
            'head_position_title' => $r->head_position_title,
            'cost_center_id' => $r->cost_center_id,
            'cost_center_name' => $r->cost_center_name,
            'reason' => $r->reason,
            'changed_by' => $r->changed_by,
            'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
        ])->all());
    }

    /**
     * El árbol a una fecha: cada unidad vigente ese día con la estructura de ese día, en orden de
     * recorrido (padres antes que hijos, hermanos por nombre).
     *
     * @param  array<int, string>|null  $legalEntities  alcance por empresa (null = toda la organización)
     * @return list<array<string, mixed>>
     */
    public function tree(string $asOf, ?array $legalEntities, ?string $selected = null, ?string $q = null, bool $includeArchived = false): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE v AS (
              SELECT ver.org_unit_id, ver.parent_id, ver.name, ver.org_unit_type_id, ver.head_position_id, ver.cost_center_id
                FROM org_unit_versions ver
                JOIN org_units u ON u.id = ver.org_unit_id AND u.deleted_at IS NULL
               WHERE ver.valid_from <= ?::date AND (ver.valid_to IS NULL OR ver.valid_to >= ?::date)
            ), t AS (
              SELECT v.org_unit_id, v.parent_id, ARRAY[lower(v.name), v.org_unit_id::text] AS sort_key, ARRAY[v.org_unit_id] AS ids, 0 AS depth
                FROM v WHERE v.parent_id IS NULL OR NOT EXISTS (SELECT 1 FROM v p WHERE p.org_unit_id = v.parent_id)
              UNION ALL
              SELECT c.org_unit_id, c.parent_id, t.sort_key || lower(c.name) || c.org_unit_id::text, t.ids || c.org_unit_id, t.depth + 1
                FROM v c JOIN t ON c.parent_id = t.org_unit_id
               WHERE NOT c.org_unit_id = ANY (t.ids)
            )
            SELECT t.org_unit_id AS id, t.depth, array_to_string(t.ids, '/') AS path,
                   (SELECT count(*) FROM v ch WHERE ch.parent_id = t.org_unit_id) AS children_count
              FROM t ORDER BY t.sort_key
            SQL;
        $rows = collect(DB::select($sql, [$asOf, $asOf]));
        if ($rows->isEmpty()) {
            return [];
        }
        $units = OrgUnit::query()->whereIn('id', $rows->pluck('id')->all())->get()->keyBy('id');
        $versions = $this->versionsAt($rows->pluck('id')->all(), $asOf);
        $names = $this->lookupNames($versions);
        $scheduled = $this->scheduledChanges($rows->pluck('id')->all());

        $out = [];
        foreach ($rows as $r) {
            /** @var OrgUnit|null $unit */
            $unit = $units->get($r->id);
            if ($unit === null) {
                continue;
            }
            if (! $includeArchived && $unit->status !== 'active' && $asOf >= now()->toDateString()) {
                continue;
            }
            if ($legalEntities !== null && $unit->legal_entity_id !== null && ! in_array($unit->legal_entity_id, $legalEntities, true)) {
                continue;
            }
            if ($selected !== null && $unit->legal_entity_id !== null && $unit->legal_entity_id !== $selected) {
                continue;
            }
            $item = array_merge($this->present($unit, $asOf, $versions[$r->id] ?? null, $names, $scheduled[$r->id] ?? false, (int) $r->children_count), [
                'depth' => (int) $r->depth,
                'path' => '/'.$r->path,
            ]);
            if ($q !== null && $q !== '' && ! str_contains(mb_strtolower($item['name'].' '.$item['code']), mb_strtolower($q))) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $version  la versión a la fecha (si ya se leyó)
     * @param  array<string, array<string, string>>|null  $names
     * @param  array{valid_from: string, parent_id: string|null, name: string}|false|null  $scheduled  false = sin cambio programado
     * @return array<string, mixed>
     */
    public function present(OrgUnit $unit, ?string $asOf = null, ?array $version = null, ?array $names = null, array|false|null $scheduled = null, ?int $children = null): array
    {
        $asOf ??= now()->toDateString();
        $version ??= $this->versionsAt([$unit->id], $asOf)[$unit->id] ?? null;
        $s = $version ?? [
            'parent_id' => $unit->parent_id, 'name' => $unit->name, 'org_unit_type_id' => $unit->org_unit_type_id,
            'head_position_id' => $unit->head_position_id, 'cost_center_id' => $unit->cost_center_id,
        ];
        $names ??= $this->lookupNames([$unit->id => $s]);
        $scheduled ??= $this->scheduledChanges([$unit->id])[$unit->id] ?? false;

        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => (string) $s['name'],
            'legal_entity_id' => $unit->legal_entity_id,
            'type_id' => $s['org_unit_type_id'],
            'type_name' => $names['types'][(string) $s['org_unit_type_id']] ?? null,
            'parent_id' => $s['parent_id'],
            'parent_name' => $names['units'][(string) $s['parent_id']] ?? null,
            'head_position_id' => $s['head_position_id'],
            'head_position_title' => $names['positions'][(string) $s['head_position_id']] ?? null,
            'head_person_name' => $names['heads'][(string) $s['head_position_id']] ?? null,
            'cost_center_id' => $s['cost_center_id'],
            'cost_center_name' => $names['cost_centers'][(string) $s['cost_center_id']] ?? null,
            'path' => $unit->path,
            'depth' => $unit->depth ?? 0,
            'valid_from' => $unit->valid_from?->toDateString(),
            'valid_to' => $unit->valid_to?->toDateString(),
            'status' => $unit->status,
            'children_count' => $children ?? DB::table('org_unit_versions')->where('parent_id', $unit->id)
                ->where('valid_from', '<=', $asOf)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $asOf))->count(),
            'scheduled_change' => $scheduled === false ? null : [
                'effective_on' => $scheduled['valid_from'],
                'parent_id' => $scheduled['parent_id'],
                'name' => $scheduled['name'],
            ],
            'as_of' => $asOf,
            'created_at' => $unit->created_at?->toIso8601String(),
            'updated_at' => $unit->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Aplica a la foto de org_units las versiones que ya rigen (cambios programados que llegaron a
     * su fecha) y recalcula rutas. Devuelve cuántas unidades cambiaron.
     */
    public function applyDueChanges(): int
    {
        $today = now()->toDateString();
        $changed = 0;
        $versions = $this->versionsAt(OrgUnit::query()->pluck('id')->all(), $today);
        foreach (OrgUnit::query()->get() as $unit) {
            $v = $versions[$unit->id] ?? null;
            if ($v === null) {
                continue;
            }
            $diff = array_filter(self::STRUCTURE, fn ($c) => $unit->getAttribute($c) !== $v[$c]);
            if ($diff !== []) {
                $unit->forceFill(array_intersect_key($v, array_flip(self::STRUCTURE)))->save();
                $changed++;
            }
        }
        if ($changed > 0) {
            $this->refreshPaths();
        }

        return $changed;
    }

    public function find(string $id): OrgUnit
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var OrgUnit */
        return OrgUnit::query()->whereKey($id)->firstOrFail();
    }

    /**
     * Opciones para el formulario: niveles, posiciones que pueden ser responsables y centros de costo.
     *
     * @param  array<int, string>|null  $legalEntities
     * @return array<string, mixed>
     */
    public function options(?array $legalEntities): array
    {
        $positions = DB::table('positions')->whereNull('deleted_at')->where('status', '!=', 'closed')
            ->when($legalEntities !== null, fn ($q) => $q->whereIn('legal_entity_id', $legalEntities ?? []))
            ->orderBy('title')->limit(500)->get(['id', 'code', 'title', 'org_unit_id']);
        $heads = $this->occupants($positions->pluck('id')->map(fn ($id) => (string) $id)->all());

        return [
            'types' => $this->types->list(),
            'positions' => $positions->map(fn ($p) => [
                'id' => (string) $p->id, 'code' => $p->code, 'title' => $p->title, 'org_unit_id' => $p->org_unit_id,
                'occupant_name' => $heads[(string) $p->id] ?? null,
            ])->values()->all(),
            'cost_centers' => DB::table('cost_centers')->whereNull('deleted_at')->where('status', 'active')
                ->when($legalEntities !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('legal_entity_id')->orWhereIn('legal_entity_id', $legalEntities ?? [])))
                ->orderBy('code')->get(['id', 'code', 'name', 'legal_entity_id'])
                ->map(fn ($c) => ['id' => (string) $c->id, 'code' => $c->code, 'name' => $c->name, 'legal_entity_id' => $c->legal_entity_id])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------------------------------

    private function day(mixed $value): string
    {
        return is_string($value) && $value !== '' ? EffectiveDated::day($value) : now()->toDateString();
    }

    /** Un cambio no puede regir antes de que la unidad exista. */
    private function assertNotBeforeStart(OrgUnit $unit, string $day): void
    {
        if ($unit->valid_from !== null && $day < $unit->valid_from->toDateString()) {
            throw new BusinessRuleViolatedException('before_unit_start', (string) trans('organization.units.before_start', ['date' => $unit->valid_from->toDateString()]));
        }
    }

    private function assertParentUsable(string $parentId, string $day): OrgUnit
    {
        $parent = preg_match('/^[0-9a-f-]{36}$/i', $parentId) === 1 ? OrgUnit::query()->whereKey($parentId)->first() : null;
        if ($parent === null || $parent->status !== 'active' || ($parent->valid_from !== null && $parent->valid_from->toDateString() > $day)) {
            throw new AyniException('validation_failed', 422, (string) trans('organization.units.unknown_parent'), [
                ['field' => 'parent_id', 'rule' => 'exists', 'message' => (string) trans('organization.units.unknown_parent')],
            ]);
        }

        return $parent;
    }

    /** RN-ORG-02: la nueva superior no puede ser la unidad ni una de sus descendientes (hoy o a la fecha). */
    private function assertMovable(OrgUnit $unit, ?string $parentId, string $day): void
    {
        if ($parentId === null) {
            return;
        }
        $this->assertParentUsable($parentId, $day);
        $blocked = array_merge([$unit->id], $this->descendantIds($unit->id, $day), $this->descendantIds($unit->id, now()->toDateString()));
        if (in_array($parentId, $blocked, true)) {
            throw new BusinessRuleViolatedException('org_unit_cycle', (string) trans('organization.units.cycle'));
        }
    }

    private function assertLegalEntity(mixed $id): void
    {
        if ($id === null) {
            return;
        }
        if (! is_string($id) || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1 || ! DB::table('legal_entities')->where('id', $id)->whereNull('deleted_at')->where('status', 'active')->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('organization.units.unknown_legal_entity'), [
                ['field' => 'legal_entity_id', 'rule' => 'exists', 'message' => (string) trans('organization.units.unknown_legal_entity')],
            ]);
        }
    }

    private function assertPosition(mixed $id): void
    {
        if ($id === null) {
            return;
        }
        if (! is_string($id) || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1 || ! DB::table('positions')->where('id', $id)->whereNull('deleted_at')->where('status', '!=', 'closed')->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('organization.units.unknown_position'), [
                ['field' => 'head_position_id', 'rule' => 'exists', 'message' => (string) trans('organization.units.unknown_position')],
            ]);
        }
    }

    private function assertCostCenter(mixed $id): void
    {
        if ($id === null) {
            return;
        }
        if (! is_string($id) || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1 || ! DB::table('cost_centers')->where('id', $id)->whereNull('deleted_at')->where('status', 'active')->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('organization.units.unknown_cost_center'), [
                ['field' => 'cost_center_id', 'rule' => 'exists', 'message' => (string) trans('organization.units.unknown_cost_center')],
            ]);
        }
    }

    /**
     * La estructura de la unidad a una fecha (o la última si la fecha cae fuera de su vida).
     *
     * @return array<string, mixed>
     */
    private function versionAt(OrgUnit $unit, string $day): array
    {
        return $this->versionsAt([$unit->id], $day)[$unit->id] ?? $this->lastVersion($unit);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastVersion(OrgUnit $unit): array
    {
        $row = DB::table('org_unit_versions')->where('org_unit_id', $unit->id)->orderByDesc('valid_from')->first(self::STRUCTURE);

        return $row === null
            ? ['parent_id' => $unit->parent_id, 'name' => $unit->name, 'org_unit_type_id' => $unit->org_unit_type_id, 'head_position_id' => $unit->head_position_id, 'cost_center_id' => $unit->cost_center_id]
            : (array) $row;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function versionsAt(array $ids, string $day): array
    {
        if ($ids === []) {
            return [];
        }
        $q = DB::table('org_unit_versions')->whereIn('org_unit_id', $ids);
        EffectiveDated::applyAsOf($q, $day);
        $out = [];
        foreach ($q->get(array_merge(['org_unit_id'], self::STRUCTURE)) as $r) {
            $row = (array) $r;
            $id = (string) $row['org_unit_id'];
            unset($row['org_unit_id']);
            $out[$id] = $row;
        }

        return $out;
    }

    /**
     * El próximo cambio programado (fecha futura) de cada unidad.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array{valid_from: string, parent_id: string|null, name: string}>
     */
    private function scheduledChanges(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('org_unit_versions')->whereIn('org_unit_id', $ids)->where('valid_from', '>', now()->toDateString())
            ->orderBy('valid_from')->get(['org_unit_id', 'valid_from', 'parent_id', 'name']);
        foreach ($rows as $r) {
            $out[(string) $r->org_unit_id] ??= ['valid_from' => (string) $r->valid_from, 'parent_id' => $r->parent_id === null ? null : (string) $r->parent_id, 'name' => (string) $r->name];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function childrenIds(string $unitId, string $day): array
    {
        $q = DB::table('org_unit_versions as v')->join('org_units as u', 'u.id', '=', 'v.org_unit_id')
            ->whereNull('u.deleted_at')->where('u.status', 'active')->where('v.parent_id', $unitId);
        EffectiveDated::applyAsOf($q, $day, 'v.valid_from', 'v.valid_to');
        // Un hijo con cambio programado (aún no vigente) también cuenta: archivar dejaría huérfana su versión futura.
        $future = DB::table('org_unit_versions')->where('parent_id', $unitId)->where('valid_from', '>', $day);

        return array_values(array_unique(array_merge(
            $q->pluck('v.org_unit_id')->map(fn ($id) => (string) $id)->all(),
            $future->pluck('org_unit_id')->map(fn ($id) => (string) $id)->all(),
        )));
    }

    /**
     * @return list<string>
     */
    private function descendantIds(string $unitId, string $day): array
    {
        $rows = DB::select(<<<'SQL'
            WITH RECURSIVE v AS (
              SELECT org_unit_id, parent_id FROM org_unit_versions
               WHERE valid_from <= ?::date AND (valid_to IS NULL OR valid_to >= ?::date)
            ), d AS (
              SELECT org_unit_id, ARRAY[org_unit_id] AS seen FROM v WHERE parent_id = ?::uuid
              UNION ALL
              SELECT v.org_unit_id, d.seen || v.org_unit_id FROM v JOIN d ON v.parent_id = d.org_unit_id WHERE NOT v.org_unit_id = ANY (d.seen)
            )
            SELECT DISTINCT org_unit_id FROM d
            SQL, [$day, $day, $unitId]);

        return array_values(array_map(fn ($r) => (string) $r->org_unit_id, $rows));
    }

    /**
     * @param  array<string, array<string, mixed>>  $versions
     * @return array<string, array<string, string>>
     */
    private function lookupNames(array $versions): array
    {
        $col = fn (string $c) => array_values(array_filter(array_unique(array_map(fn ($v) => $v[$c] ?? null, $versions))));
        $positions = $col('head_position_id');

        return [
            'units' => DB::table('org_units')->whereIn('id', $col('parent_id'))->pluck('name', 'id')->map(fn ($v) => (string) $v)->all(),
            'types' => DB::table('org_unit_types')->whereIn('id', $col('org_unit_type_id'))->pluck('name', 'id')->map(fn ($v) => (string) $v)->all(),
            'positions' => DB::table('positions')->whereIn('id', $positions)->pluck('title', 'id')->map(fn ($v) => (string) $v)->all(),
            'heads' => $this->occupants($positions),
            'cost_centers' => DB::table('cost_centers')->whereIn('id', $col('cost_center_id'))->pluck('name', 'id')->map(fn ($v) => (string) $v)->all(),
        ];
    }

    /**
     * Nombre de quien ocupa hoy cada posición (asignación vigente).
     *
     * @param  array<int, string>  $positionIds
     * @return array<string, string>
     */
    private function occupants(array $positionIds): array
    {
        if ($positionIds === []) {
            return [];
        }
        $q = DB::table('employment_assignments as a')
            ->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereIn('a.position_id', $positionIds)
            ->whereIn('e.status', ['active', 'suspended', 'terminating']);
        EffectiveDated::applyAsOf($q, now(), 'a.valid_from', 'a.valid_to');
        $out = [];
        foreach ($q->get(['a.position_id', 'p.first_names', 'p.paternal_surname']) as $r) {
            $out[(string) $r->position_id] ??= trim($r->first_names.' '.($r->paternal_surname ?? ''));
        }

        return $out;
    }

    /** Copia a org_units la versión vigente hoy de la unidad y recalcula rutas. */
    private function syncSnapshot(OrgUnit $unit): void
    {
        $v = $this->versionsAt([$unit->id], now()->toDateString())[$unit->id] ?? null;
        if ($v !== null) {
            $unit->forceFill(array_intersect_key($v, array_flip(self::STRUCTURE)))->save();
        }
        $this->refreshPaths();
    }

    /** Recalcula ruta y profundidad de la foto vigente (las unidades de una organización caben en una consulta). */
    private function refreshPaths(): void
    {
        DB::statement(<<<'SQL'
            WITH RECURSIVE t AS (
              SELECT u.id, '/' || u.id::text AS path, 0 AS depth, ARRAY[u.id] AS seen
                FROM org_units u
               WHERE u.deleted_at IS NULL AND (u.parent_id IS NULL OR NOT EXISTS (SELECT 1 FROM org_units p WHERE p.id = u.parent_id AND p.deleted_at IS NULL))
              UNION ALL
              SELECT c.id, t.path || '/' || c.id::text, t.depth + 1, t.seen || c.id
                FROM org_units c JOIN t ON c.parent_id = t.id
               WHERE c.deleted_at IS NULL AND NOT c.id = ANY (t.seen)
            )
            UPDATE org_units o SET path = t.path, depth = t.depth
              FROM t WHERE o.id = t.id AND (o.path IS DISTINCT FROM t.path OR o.depth IS DISTINCT FROM t.depth)
            SQL);
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     */
    private function emitStructureEvents(OrgUnit $unit, array $current, array $changes, string $day): void
    {
        if (array_key_exists('parent_id', $changes)) {
            $this->outbox->record(DomainEventTypes::ORG_UNIT_MOVED, 'org_unit', $unit->id, [
                'org_unit_id' => $unit->id, 'from_parent_id' => $current['parent_id'] ?? null, 'to_parent_id' => $changes['parent_id'], 'effective_on' => $day,
            ]);
        }
        if (array_key_exists('head_position_id', $changes)) {
            $this->outbox->record(DomainEventTypes::ORG_UNIT_MANAGER_CHANGED, 'org_unit', $unit->id, [
                'org_unit_id' => $unit->id, 'from_position_id' => $current['head_position_id'] ?? null, 'to_position_id' => $changes['head_position_id'], 'effective_on' => $day,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(OrgUnit $unit): array
    {
        return [
            'code' => $unit->code, 'name' => $unit->name, 'legal_entity_id' => $unit->legal_entity_id, 'parent_id' => $unit->parent_id,
            'org_unit_type_id' => $unit->org_unit_type_id, 'head_position_id' => $unit->head_position_id,
            'cost_center_id' => $unit->cost_center_id, 'status' => $unit->status,
            'valid_from' => $unit->valid_from?->toDateString(), 'valid_to' => $unit->valid_to?->toDateString(),
        ];
    }
}
