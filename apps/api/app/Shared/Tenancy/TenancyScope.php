<?php

namespace App\Shared\Tenancy;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * TenancyScope — gestiona el tenant_id activo en la transacción y en la sesión de base de datos.
 *
 * Usos permitidos de withoutGlobalScopes():
 *  - Solo en esta capa. Cualquier otro uso rompe la regla de aislamiento.
 *    La prueba de arquitectura tests/Architecture/ModuleBoundariesTest.php
 *    verificará esto automáticamente.
 */
final class TenancyScope
{
    private static bool $syncWithDatabase = true;

    public static function current(): ?string
    {
        return app()->bound('tenant.id') ? (string) app('tenant.id') : null;
    }

    public static function requireTenant(): string
    {
        $tenantId = self::current();
        if ($tenantId === null || $tenantId === '') {
            throw new TenantContextRequiredException;
        }

        return $tenantId;
    }

    public static function set(string $tenantId, bool $syncDb = true): void
    {
        if (! self::isValidUuid($tenantId)) {
            throw new InvalidArgumentException("Formato de tenant_id inválido: {$tenantId}");
        }

        app()->instance('tenant.id', $tenantId);

        if ($syncDb && self::$syncWithDatabase) {
            self::applyToDatabase($tenantId);
        }
    }

    public static function clear(bool $syncDb = true): void
    {
        app()->forgetInstance('tenant.id');

        if ($syncDb && self::$syncWithDatabase) {
            self::resetDatabase();
        }
    }

    /**
     * Ejecuta una acción dentro del contexto de una organización específica y restaura el contexto previo.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runAs(string $tenantId, callable $callback): mixed
    {
        $previousTenantId = self::current();
        self::set($tenantId);

        try {
            return $callback();
        } finally {
            if ($previousTenantId !== null) {
                self::set($previousTenantId);
            } else {
                self::clear();
            }
        }
    }

    /**
     * Ejecuta una acción sin contexto de organización (modo plataforma) y restaura el contexto previo.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runWithoutTenant(callable $callback): mixed
    {
        $previousTenantId = self::current();
        self::clear();

        try {
            return $callback();
        } finally {
            if ($previousTenantId !== null) {
                self::set($previousTenantId);
            }
        }
    }

    public static function setSyncWithDatabase(bool $sync): void
    {
        self::$syncWithDatabase = $sync;
    }

    public static function isSyncWithDatabase(): bool
    {
        return self::$syncWithDatabase;
    }

    public static function applyToDatabase(string $tenantId): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
                DB::statement('SET ROLE gestia_app');
            }
        } catch (Throwable) {
            // Silencioso si no hay conexión de BD activa o la conexión está en inicialización
        }
    }

    public static function resetDatabase(): void
    {
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('RESET ROLE');
                DB::statement("SELECT set_config('app.tenant_id', '', false)");
            }
        } catch (Throwable) {
            // Silencioso si no hay conexión de BD activa
        }
    }

    public static function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}
