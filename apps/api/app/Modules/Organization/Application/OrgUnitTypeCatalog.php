<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Models\OrgUnitType;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;

/**
 * ORG-3 · Niveles de unidad con los nombres de cada organización. Una organización nueva parte de
 * los seis niveles de la especificación (gerencia → equipo) y puede renombrarlos, reordenarlos o
 * agregar los suyos (p. ej. «Jefatura», «Célula»).
 */
final class OrgUnitTypeCatalog
{
    /** Niveles iniciales, del más alto al más bajo (M-ORG: profundidad libre, tipo configurable). */
    public const DEFAULTS = ['management', 'area', 'department', 'subdepartment', 'unit', 'team'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Crea los niveles iniciales si la organización todavía no tiene ninguno (idempotente). */
    public function ensureDefaults(): void
    {
        if (OrgUnitType::query()->withTrashed()->exists()) {
            return;
        }
        DB::transaction(function (): void {
            foreach (self::DEFAULTS as $i => $code) {
                OrgUnitType::query()->create(['code' => $code, 'name' => (string) trans("organization.unit_types.{$code}"), 'rank' => $i + 1]);
            }
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $this->ensureDefaults();
        $counts = DB::table('org_units')->whereNull('deleted_at')->where('status', 'active')->whereNotNull('org_unit_type_id')
            ->groupBy('org_unit_type_id')->selectRaw('org_unit_type_id, count(*) AS n')->pluck('n', 'org_unit_type_id');

        return array_values(OrgUnitType::query()->orderBy('rank')->orderBy('name')->get()
            ->map(fn (OrgUnitType $t) => $this->present($t, (int) ($counts[$t->id] ?? 0)))->all());
    }

    /**
     * @param  array{code: string, name: string, rank?: int|null}  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->ensureDefaults();
        if (OrgUnitType::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('organization.units.type_code_exists'));
        }
        $rank = $data['rank'] ?? ((int) OrgUnitType::query()->max('rank') + 1);

        return DB::transaction(function () use ($data, $rank): array {
            $type = OrgUnitType::query()->create(['code' => $data['code'], 'name' => $data['name'], 'rank' => $rank]);
            $this->audit->record('org_unit_type.created', 'org_unit_type', $type->id, null, $type->only(['code', 'name', 'rank']));

            return $this->present($type->refresh(), 0);
        });
    }

    /**
     * @param  array{name?: string, rank?: int}  $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $type = $this->find($id);
        $before = $type->only(['name', 'rank']);

        return DB::transaction(function () use ($type, $data, $before): array {
            $type->fill($data)->save();
            $this->audit->record('org_unit_type.updated', 'org_unit_type', $type->id, $before, $type->only(['name', 'rank']));
            $count = DB::table('org_units')->whereNull('deleted_at')->where('org_unit_type_id', $type->id)->where('status', 'active')->count();

            return $this->present($type->refresh(), $count);
        });
    }

    public function find(string $id): OrgUnitType
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var OrgUnitType */
        return OrgUnitType::query()->whereKey($id)->firstOrFail();
    }

    /** Un tipo activo de la organización, o 422 si no existe. */
    public function assertUsable(string $id): void
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1 || ! OrgUnitType::query()->whereKey($id)->where('status', 'active')->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('organization.units.unknown_type'), [
                ['field' => 'type_id', 'rule' => 'exists', 'message' => (string) trans('organization.units.unknown_type')],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrgUnitType $t, int $units): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'rank' => $t->rank,
            'status' => $t->status,
            'units_count' => $units,
        ];
    }
}
