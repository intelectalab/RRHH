<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\OrgUnitService;
use App\Modules\Organization\Application\OrgUnitTypeCatalog;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ORG-3 · Árbol de unidades organizacionales. Lectura a una fecha (?as_of=) con la estructura de ese
 * día; crear, editar y mover registran una versión desde effective_from / effective_on.
 * Alcance: una unidad de una empresa solo la ve quien tiene esa empresa en su alcance; las unidades
 * sin empresa (de todo el grupo) las ve cualquiera con org.units.view.
 */
final class OrgUnitController
{
    public function __construct(
        private readonly OrgUnitService $units,
        private readonly OrgUnitTypeCatalog $types,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $q = $request->query('q');
        $filter = $request->query('filter');
        $includeArchived = is_array($filter) && ($filter['status'] ?? null) === 'all';
        $rows = $this->units->tree($asOf, $this->allowed($request, 'org.units.view'), DataScope::selectedLegalEntity($request), is_string($q) ? $q : null, $includeArchived);

        return response()->json([
            'data' => $rows,
            'meta' => ['per_page' => max(1, count($rows)), 'next_cursor' => null, 'as_of' => $asOf],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->units->present($this->visible($request, $id), $this->asOf($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $this->assertInScope($request, $data['legal_entity_id'] ?? null, 'org.units.create');
        $unit = $this->units->create($data, $this->userId($request));

        return response()->json(['data' => $this->units->present($unit)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $unit = $this->visible($request, $id, 'org.units.update');
        $data = $request->validate($this->rules(false));
        if (array_key_exists('legal_entity_id', $data)) {
            $this->assertInScope($request, $data['legal_entity_id'], 'org.units.update');
        }
        $unit = $this->units->update($unit, $data, $this->userId($request));

        return response()->json(['data' => $this->units->present($unit)]);
    }

    public function move(Request $request, string $id): JsonResponse
    {
        $unit = $this->visible($request, $id, 'org.units.move');
        $data = $request->validate([
            'parent_id' => ['present', 'nullable', 'uuid'],
            'effective_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $unit = $this->units->move($unit, $data['parent_id'], $data['effective_on'] ?? null, $data['reason'] ?? null, $this->userId($request));

        return response()->json(['data' => $this->units->present($unit)]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $unit = $this->visible($request, $id, 'org.units.archive');
        $data = $request->validate([
            'effective_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $unit = $this->units->archive($unit, $data['effective_on'] ?? null, $data['reason'] ?? null);

        return response()->json(['data' => $this->units->present($unit)]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $unit = $this->visible($request, $id, 'org.units.restore');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $unit = $this->units->restore($unit, $data['reason'] ?? null, $this->userId($request));

        return response()->json(['data' => $this->units->present($unit)]);
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $unit = $this->visible($request, $id);

        return response()->json(['data' => $this->units->history($unit)]);
    }

    public function impact(Request $request, string $id): JsonResponse
    {
        $unit = $this->visible($request, $id);

        return response()->json(['data' => $this->units->impact($unit)]);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->units->options($this->allowed($request, 'org.units.view'))]);
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => $this->types->list()]);
    }

    public function storeType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'rank' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
        ]);

        return response()->json(['data' => $this->types->create($data)], 201);
    }

    public function updateType(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'rank' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);

        return response()->json(['data' => $this->types->update($id, $data)]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'type_id' => [$req, 'uuid'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'head_position_id' => ['sometimes', 'nullable', 'uuid'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** Una unidad visible para el usuario (404 si no existe o está fuera de su alcance de empresa). */
    private function visible(Request $request, string $id, string $permission = 'org.units.view'): OrgUnit
    {
        $unit = $this->units->find($id);
        $allowed = $this->allowed($request, $permission);
        abort_if($allowed !== null && $unit->legal_entity_id !== null && ! in_array($unit->legal_entity_id, $allowed, true), 404);

        return $unit;
    }

    private function assertInScope(Request $request, mixed $legalEntity, string $permission): void
    {
        $allowed = $this->allowed($request, $permission);
        if ($allowed === null) {
            return;
        }
        // Con alcance acotado solo se crean o pasan unidades a empresas del propio alcance.
        abort_if(! is_string($legalEntity) || ! in_array(strtolower($legalEntity), $allowed, true), 403);
    }

    /**
     * @return array<int, string>|null
     */
    private function allowed(Request $request, string $permission): ?array
    {
        return $this->scope->legalEntityIds($this->userId($request) ?? '', $permission);
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }

    private function asOf(Request $request): string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null) {
            return now()->toDateString();
        }
        $request->validate(['as_of' => ['date_format:Y-m-d']]);

        return (string) $asOf;
    }
}
