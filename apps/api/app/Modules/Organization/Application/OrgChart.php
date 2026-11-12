<?php

namespace App\Modules\Organization\Application;

use App\Shared\Permissions\DataScope;
use Illuminate\Http\Request;

/**
 * ORG-6 · Organigrama a una fecha.
 *
 *  - units: el árbol de unidades de ORG-3 con la estructura de ese día, el responsable (quien ocupa
 *    ese día la posición responsable) y cuántas personas tiene directamente.
 *  - people: la línea de reporte vigente ese día (PeopleDirectory::reportingTree).
 *
 * Nada se guarda aparte: se calcula sobre versiones y asignaciones con vigencia, así que cambiar un
 * jefe, un responsable o mover una unidad se ve sin pasos adicionales, y una fecha pasada muestra la
 * estructura de entonces.
 */
final class OrgChart
{
    public function __construct(
        private readonly OrgUnitService $units,
        private readonly PeopleDirectory $people,
        private readonly DataScope $scope,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function units(Request $request, string $day): array
    {
        $user = $request->user();
        $allowed = $this->scope->legalEntityIds($user === null ? '' : (string) $user->getAuthIdentifier(), PeopleDirectory::PERMISSION);
        $tree = $this->units->tree($day, $allowed, DataScope::selectedLegalEntity($request));
        $heads = $this->people->occupantsAt(array_values(array_filter(array_map(fn ($u) => $u['head_position_id'] === null ? null : (string) $u['head_position_id'], $tree))), $day);
        $counts = $this->people->headcountByUnit($request, $day);
        $ids = array_flip(array_map(fn ($u) => (string) $u['id'], $tree));

        return array_map(function (array $u) use ($heads, $counts, $ids): array {
            $head = $u['head_position_id'] === null ? null : ($heads[(string) $u['head_position_id']] ?? null);

            return [
                'id' => (string) $u['id'],
                'parent_id' => $u['parent_id'] !== null && isset($ids[(string) $u['parent_id']]) ? (string) $u['parent_id'] : null,
                'code' => $u['code'],
                'name' => $u['name'],
                'type_name' => $u['type_name'],
                'legal_entity_id' => $u['legal_entity_id'],
                'head_position_title' => $u['head_position_title'],
                'head_name' => $head['name'] ?? null,
                'head_employment_id' => $head['employment_id'] ?? null,
                'people_count' => $counts[(string) $u['id']] ?? 0,
                'status' => $u['status'],
            ];
        }, $tree);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function people(Request $request, string $day): array
    {
        return $this->people->reportingTree($request, $day);
    }
}
