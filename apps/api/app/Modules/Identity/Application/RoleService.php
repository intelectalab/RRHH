<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SEG-7 · Roles del sistema y personalizados.
 *
 * - RN-SEG-01: un rol de sistema no se edita ni se elimina; solo se duplica.
 * - RN-SEG-03: solo se conceden permisos que quien edita ya tiene, salvo el Administrador empresa.
 * - Todo cambio queda en la auditoría con antes y después; se aplica de inmediato.
 */
final class RoleService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Role $role): array
    {
        $grants = $role->grants()->join('permissions as p', 'p.code', '=', 'role_permissions.permission_code')
            ->orderBy('role_permissions.permission_code')
            ->get(['role_permissions.permission_code', 'role_permissions.scope', 'p.kind']);

        $scopes = $grants->pluck('scope')->countBy()->sortDesc();

        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'system' => $role->is_system,
            'mfa_required' => $this->mfaRequired($role),
            'permissions' => $grants->where('kind', 'action')->pluck('permission_code')->values()->all(),
            'field_permissions' => $grants->where('kind', 'field')->pluck('permission_code')->values()->all(),
            'scope' => RoleScopes::toApi((string) ($scopes->keys()->first() ?? 'organization')),
            'version' => $role->lock_version,
            'created_at' => $role->created_at->toIso8601String(),
            'updated_at' => $role->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param  array{name: string, description?: string|null, permissions?: array<int, string>, field_permissions?: array<int, string>, scope?: string}  $input
     */
    public function create(array $input, EffectivePermissions $actor): Role
    {
        $codes = $this->validatedCodes($input, $actor);

        return DB::transaction(function () use ($input, $codes): Role {
            $role = Role::create([
                'code' => $this->uniqueCode($input['name']),
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'is_system' => false,
            ]);
            $this->syncGrants($role, $codes, RoleScopes::toDb($input['scope'] ?? 'organization'));

            $after = $this->present($role->refresh());
            $this->audit->record('role.created', 'role', $role->id, null, $after);

            return $role;
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, permissions?: array<int, string>, field_permissions?: array<int, string>, scope?: string}  $input
     */
    public function update(Role $role, array $input, EffectivePermissions $actor, ?int $expectedVersion): Role
    {
        $this->guardNotSystem($role);
        if ($expectedVersion !== null && $expectedVersion !== $role->lock_version) {
            throw new VersionConflictException($expectedVersion, $role->lock_version);
        }

        $codes = (array_key_exists('permissions', $input) || array_key_exists('field_permissions', $input))
            ? $this->validatedCodes($input + ['permissions' => [], 'field_permissions' => []], $actor)
            : null;

        return DB::transaction(function () use ($role, $input, $codes): Role {
            $before = $this->present($role);

            $role->fill(array_intersect_key($input, array_flip(['name', 'description'])));
            if ($role->isDirty()) {
                $role->save();
            }

            $scope = isset($input['scope']) ? RoleScopes::toDb($input['scope']) : RoleScopes::toDb((string) $before['scope']);
            if ($codes !== null || isset($input['scope'])) {
                $this->syncGrants($role, $codes ?? array_merge((array) $before['permissions'], (array) $before['field_permissions']), $scope);
                // El cambio de matriz también versiona el rol.
                DB::table('roles')->where('id', $role->id)->update(['updated_at' => now()]);
            }

            $role->refresh();
            $this->audit->record('role.updated', 'role', $role->id, $before, $this->present($role));
            $this->permissions->forget();

            return $role;
        });
    }

    /**
     * SEG-3 · RN-SEG-08: exigir (o no) el segundo factor a quienes tengan el rol. Vale también para
     * los roles de sistema, que por lo demás no se editan. Las sesiones abiertas lo piden en su próxima petición.
     */
    public function setMfaRequirement(Role $role, bool $required, ?string $reason): Role
    {
        return DB::transaction(function () use ($role, $required, $reason): Role {
            $before = $this->mfaRequired($role);
            DB::table('roles')->where('id', $role->id)->update(['mfa_required' => $required, 'updated_at' => now()]);
            $this->audit->record('role.mfa_requirement_changed', 'role', $role->id, ['mfa_required' => $before], ['mfa_required' => $required], $reason);

            return $role->refresh();
        });
    }

    private function mfaRequired(Role $role): bool
    {
        $row = DB::table('roles as r')->leftJoin('system_roles as sr', 'sr.code', '=', 'r.system_role_code')
            ->where('r.id', $role->id)->selectRaw('COALESCE(r.mfa_required, sr.mfa_required_default, false) AS required')->first();

        return $row !== null && (bool) $row->required;
    }

    public function delete(Role $role): void
    {
        $this->guardNotSystem($role);

        if ($role->assignments()->exists()) {
            throw new AyniException('in_use', 409, null, [], ['references' => ['user_roles']]);
        }

        DB::transaction(function () use ($role): void {
            $before = $this->present($role);
            $role->delete();
            $this->audit->record('role.deleted', 'role', $role->id, $before, null);
        });
    }

    public function duplicate(Role $source, ?string $name): Role
    {
        return DB::transaction(function () use ($source, $name): Role {
            $data = $this->present($source);
            $copyName = $name ?? (string) trans('identity.roles.copy_of', ['name' => $source->name]);

            $role = Role::create([
                'code' => $this->uniqueCode($copyName),
                'name' => $copyName,
                'description' => $source->description,
                'is_system' => false,
            ]);

            foreach ($source->grants()->get(['permission_code', 'scope']) as $grant) {
                $role->grants()->create(['permission_code' => $grant->permission_code, 'scope' => $grant->scope]);
            }

            $this->audit->record('role.duplicated', 'role', $role->id, ['source_role_id' => $source->id, 'source' => $data], $this->present($role->refresh()));

            return $role;
        });
    }

    private function guardNotSystem(Role $role): void
    {
        if ($role->is_system) {
            throw new BusinessRuleViolatedException('RN-SEG-01', (string) trans('identity.roles.system_immutable'));
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    private function validatedCodes(array $input, EffectivePermissions $actor): array
    {
        /** @var array<int, string> $actions */
        $actions = array_values(array_unique((array) ($input['permissions'] ?? [])));
        /** @var array<int, string> $fields */
        $fields = array_values(array_unique((array) ($input['field_permissions'] ?? [])));

        $catalog = DB::table('permissions')->whereIn('code', array_merge($actions, $fields))->pluck('kind', 'code');

        $errors = [];
        foreach ($actions as $code) {
            if (($catalog[$code] ?? null) !== 'action') {
                $errors['permissions'][] = (string) trans('identity.roles.unknown_permission', ['code' => $code]);
            }
        }
        foreach ($fields as $code) {
            if (($catalog[$code] ?? null) !== 'field') {
                $errors['field_permissions'][] = (string) trans('identity.roles.unknown_permission', ['code' => $code]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // RN-SEG-03: nadie concede lo que no tiene, salvo el Administrador empresa.
        if (! $actor->isCompanyAdmin()) {
            $missing = array_values(array_filter(array_merge($actions, $fields), fn (string $c) => ! $actor->has($c)));
            if ($missing !== []) {
                throw new BusinessRuleViolatedException('RN-SEG-03', (string) trans('identity.roles.cannot_grant', ['codes' => implode(', ', $missing)]));
            }
        }

        return array_merge($actions, $fields);
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function syncGrants(Role $role, array $codes, string $scope): void
    {
        $role->grants()->whereNotIn('permission_code', $codes)->delete();
        $role->grants()->whereIn('permission_code', $codes)->where('scope', '!=', $scope)->update(['scope' => $scope]);

        $existing = $role->grants()->pluck('permission_code')->all();
        foreach (array_diff($codes, $existing) as $code) {
            $role->grants()->create(['permission_code' => $code, 'scope' => $scope]);
        }
    }

    private function uniqueCode(string $name): string
    {
        $base = 'custom_'.Str::limit(Str::slug($name, '_'), 40, '');
        $code = $base;
        $i = 2;
        while (Role::query()->where('code', $code)->exists()) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
