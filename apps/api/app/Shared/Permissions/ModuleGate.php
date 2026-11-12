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
 *
 * SAS-4 · Con la prueba vencida sin conversión, toda la organización queda en solo lectura (tenantReadOnly).
 */
final class ModuleGate
{
    public const FULL = 'full';

    public const READ_ONLY = 'read_only';

    public const NONE = 'none';

    /** @var array<string, array<string, string>> */
    private array $memo = [];

    /** @var array<string, bool> */
    private array $readOnlyMemo = [];

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

    /**
     * SAS-4 · RN-SAS-04: la prueba venció sin conversión y la organización está en el periodo de gracia:
     * se consulta todo, pero nada se modifica hasta que se convierta (o se suspenda al terminar la gracia).
     * Una gracia por pago vencido (sin fecha de prueba) opera con normalidad (ME-40).
     */
    public function tenantReadOnly(): bool
    {
        $tenant = TenancyScope::requireTenant();
        if (! isset($this->readOnlyMemo[$tenant])) {
            $row = DB::table('current_tenant')->first(['status', 'trial_ends_at']);
            $this->readOnlyMemo[$tenant] = $row !== null && $row->status === 'grace_period' && $row->trial_ends_at !== null;
        }

        return $this->readOnlyMemo[$tenant];
    }

    public function forget(): void
    {
        $this->memo = [];
        $this->readOnlyMemo = [];
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
