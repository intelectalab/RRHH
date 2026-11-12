<?php

namespace App\Shared\Permissions;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Módulos contratados y activos de la organización (regla 10 de CLAUDE.md, RN-SAS-01).
 *
 * SAS-5 · Un módulo retirado no borra datos: durante el periodo de transición (RN-SAS-06) queda en
 * solo lectura hasta read_only_until (la consola lo fija con el parámetro de plataforma
 * modules.read_only_transition_days) y después se oculta.
 */
final class ModuleGate
{
    public const FULL = 'full';

    public const READ_ONLY = 'read_only';

    public const NONE = 'none';

    /** @var array<string, array<string, string>> */
    private array $memo = [];

    /**
     * Módulos con acceso completo (activos o en prueba).
     *
     * @return array<int, string>
     */
    public function activeModules(): array
    {
        return array_keys(array_filter($this->access(), fn (string $a): bool => $a === self::FULL));
    }

    /**
     * Módulos retirados que siguen en solo lectura.
     *
     * @return array<int, string>
     */
    public function readOnlyModules(): array
    {
        return array_keys(array_filter($this->access(), fn (string $a): bool => $a === self::READ_ONLY));
    }

    public function isEnabled(string $moduleCode): bool
    {
        return ($this->access()[$moduleCode] ?? self::NONE) === self::FULL;
    }

    public function accessTo(string $moduleCode): string
    {
        return $this->access()[$moduleCode] ?? self::NONE;
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

    /**
     * @return array<string, string> código → acceso
     */
    private function access(): array
    {
        $tenant = TenancyScope::requireTenant();
        if (isset($this->memo[$tenant])) {
            return $this->memo[$tenant];
        }

        $rows = DB::table('tenant_modules')->orderBy('module_code')->get(['module_code', 'status', 'read_only_until']);
        $access = [];
        foreach ($rows as $row) {
            if (in_array($row->status, ['active', 'trial'], true)) {
                $access[(string) $row->module_code] = self::FULL;
            } elseif ($row->status === 'inactive' && $row->read_only_until !== null && Carbon::parse((string) $row->read_only_until)->isFuture()) {
                $access[(string) $row->module_code] = self::READ_ONLY;
            }
        }

        return $this->memo[$tenant] = $access;
    }
}
