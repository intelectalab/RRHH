<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Application\RoleScopes;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /me · Usuario actual, organización, roles, permisos efectivos, alcance y módulos.
 * El cliente construye menús y botones con esto; el servidor valida igual cada acción.
 */
final class MeController
{
    public function __invoke(Request $request, PermissionResolver $resolver, ModuleGate $modules): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $effective = $resolver->for($user->id);
        $tenant = DB::table('current_tenant')->first(['id', 'slug', 'name', 'default_locale', 'timezone', 'default_currency']);

        $roles = Role::query()
            ->whereIn('id', $user->roleAssignments()->pluck('role_id'))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'is_system']);

        return response()->json(['data' => [
            'user' => [
                'id' => $user->id,
                'display_name' => $user->display_name,
                'locale' => $user->locale ?? $tenant?->default_locale,
                'timezone' => $user->timezone ?? $tenant?->timezone,
                'must_change_password' => $user->must_change_password,
            ],
            'tenant' => $tenant === null ? null : [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'currency' => $tenant->default_currency,
            ],
            'roles' => $roles->map(fn (Role $r) => ['id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'system' => $r->is_system])->all(),
            'permissions' => array_keys($effective->actions),
            'field_permissions' => array_keys($effective->fields),
            'scopes' => array_map(fn (string $s) => RoleScopes::toApi($s), $effective->actions + $effective->fields),
            'modules' => $modules->activeModules(),
        ]]);
    }
}
