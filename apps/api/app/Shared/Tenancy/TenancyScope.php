<?php

namespace App\Shared\Tenancy;

/**
 * TenancyScope — resuelve el tenant_id activo en la transacción.
 *
 * Usos permitidos de withoutGlobalScopes():
 *  - Solo en esta capa. Cualquier otro uso rompe la regla de aislamiento.
 *    La prueba de arquitectura tests/Architecture/ModuleBoundariesTest.php
 *    verificará esto automáticamente.
 */
final class TenancyScope
{
    public static function current(): ?string
    {
        return app()->bound('tenant.id') ? (string) app('tenant.id') : null;
    }

    public static function set(string $tenantId): void
    {
        app()->instance('tenant.id', $tenantId);
    }

    public static function clear(): void
    {
        app()->forgetInstance('tenant.id');
    }
}
