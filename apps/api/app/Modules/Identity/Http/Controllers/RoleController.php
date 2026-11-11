<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\RoleService;
use App\Modules\Identity\Http\Requests\RoleRequest;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RoleController
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = Role::query();

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'name');
        $ids = array_column($page['data'], 'id');
        $models = Role::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => array_values(array_map(
                fn (Role $role) => $this->roles->present($role),
                array_filter(array_map(fn ($id) => $models->get($id), $ids), fn ($r) => $r instanceof Role),
            )),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $role = $this->find($id);

        return $this->respond($role);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        /** @var array{name: string, description?: string|null, permissions?: array<int, string>, field_permissions?: array<int, string>, scope?: string} $input */
        $input = $request->validated();
        $role = $this->roles->create($input, $this->actor($request));

        return $this->respond($role, 201);
    }

    public function update(RoleRequest $request, string $id): JsonResponse
    {
        $role = $this->find($id);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        /** @var array{name?: string, description?: string|null, permissions?: array<int, string>, field_permissions?: array<int, string>, scope?: string} $input */
        $input = $request->validated();
        $role = $this->roles->update($role, $input, $this->actor($request), $ifMatch === '' ? null : (int) $ifMatch);

        return $this->respond($role);
    }

    public function destroy(string $id): Response
    {
        $this->roles->delete($this->find($id));

        return response()->noContent();
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $source = $this->find($id);
        $request->validate(['name' => ['sometimes', 'string', 'min:2', 'max:120']]);
        $name = $request->input('name');
        $role = $this->roles->duplicate($source, is_string($name) ? $name : null);

        // Las acciones responden 200 según el contrato (POST /recurso/{id}/accion).
        return $this->respond($role);
    }

    public function mfaRequirement(Request $request, string $id): JsonResponse
    {
        $role = $this->find($id);
        $data = $request->validate([
            'required' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        return $this->respond($this->roles->setMfaRequirement($role, (bool) $data['required'], (string) $data['reason']));
    }

    private function find(string $id): Role
    {
        /** @var Role */
        return Role::query()->whereKey($this->uuidOrAbort($id))->firstOrFail();
    }

    private function uuidOrAbort(string $id): string
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        return $id;
    }

    private function actor(Request $request): EffectivePermissions
    {
        /** @var EffectivePermissions */
        return $request->attributes->get('effective_permissions');
    }

    private function respond(Role $role, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->roles->present($role)], $status)
            ->header('ETag', '"'.$role->lock_version.'"');
    }
}
