<?php

namespace Tests\Support;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * Usuarios de prueba con permisos concretos. `null` = todos los permisos del catálogo.
 */
final class IdentityFixtures
{
    /**
     * @param  array<int, string>|null  $permissions
     */
    public static function userWithPermissions(string $tenantId, ?array $permissions = null, string $scope = 'organization', ?string $systemRole = null): User
    {
        return TenancyScope::runAs($tenantId, function () use ($permissions, $scope, $systemRole): User {
            self::enableAllModules();

            $codes = $permissions ?? DB::table('permissions')->pluck('code')->map(fn ($c) => (string) $c)->all();
            $role = Role::factory()->withPermissions($codes, $scope)->create(
                $systemRole === null ? [] : ['is_system' => true, 'system_role_code' => $systemRole, 'code' => $systemRole.'_'.substr(md5((string) microtime(true)), 0, 6)]
            );
            $user = User::factory()->create();
            $user->roleAssignments()->create(['role_id' => $role->id]);

            return $user;
        });
    }

    public static function enableAllModules(): void
    {
        $tenant = TenancyScope::requireTenant();
        foreach (DB::table('modules')->pluck('code') as $code) {
            DB::table('tenant_modules')->insertOrIgnore([
                'tenant_id' => $tenant, 'module_code' => $code, 'status' => 'active', 'source' => 'manual', 'activated_at' => now(),
            ]);
        }
    }

    public static function disableModule(string $tenantId, string $module): void
    {
        TenancyScope::runAs($tenantId, fn () => DB::table('tenant_modules')->where('module_code', $module)->update(['status' => 'inactive']));
    }
}
