<?php

namespace App\Shared\Permissions;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * Módulos contratados y activos de la organización (regla 10 de CLAUDE.md).
 */
final class ModuleGate
{
    /** @var array<string, array<int, string>> */
    private array $memo = [];

    /**
     * @return array<int, string>
     */
    public function activeModules(): array
    {
        $tenant = TenancyScope::requireTenant();

        return $this->memo[$tenant] ??= DB::table('tenant_modules')
            ->whereIn('status', ['active', 'trial'])
            ->orderBy('module_code')
            ->pluck('module_code')
            ->map(fn ($c) => (string) $c)
            ->all();
    }

    public function isEnabled(string $moduleCode): bool
    {
        return in_array($moduleCode, $this->activeModules(), true);
    }

    public function moduleOf(string $permissionCode): ?string
    {
        $module = DB::table('permissions')->where('code', $permissionCode)->value('module_code');

        return $module === null ? null : (string) $module;
    }

    public function forget(): void
    {
        $this->memo = [];
    }
}
