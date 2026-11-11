<?php

namespace App\Shared\Tenancy;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tareas programadas de mantenimiento que, por naturaleza, salen del contexto de una organización
 * (AUD-5: retirar particiones mensuales ya archivadas por todas). Es el único lugar fuera de
 * TenancyScope que cambia de rol de base de datos; los módulos lo usan en vez de hacerlo ellos.
 */
final class CrossTenantMaintenance
{
    /**
     * Ejecuta sin organización activa (tarea de plataforma).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutTenant(callable $callback): mixed
    {
        return TenancyScope::runWithoutTenant($callback);
    }

    /**
     * Lectura entre organizaciones con el rol de plataforma (BYPASSRLS), solo dentro de esta transacción.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function readAcrossTenants(callable $callback): mixed
    {
        if (TenancyScope::current() !== null) {
            throw new \RuntimeException('La lectura entre organizaciones se hace sin organización activa.');
        }

        return DB::transaction(function () use ($callback) {
            DB::statement('SET LOCAL ROLE gestia_platform');
            try {
                return $callback();
            } finally {
                DB::statement('RESET ROLE');
            }
        });
    }

    /**
     * Escritura con el rol propietario (sin el rol de aplicación), manteniendo la organización activa
     * para que rija su política de aislamiento. Para tablas que la aplicación no puede reescribir.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function asOwner(callable $callback): mixed
    {
        TenancyScope::requireTenant();
        $sync = TenancyScope::isSyncWithDatabase() && DB::getDriverName() === 'pgsql';
        if ($sync) {
            DB::statement('RESET ROLE');
        }
        try {
            return $callback();
        } finally {
            if ($sync) {
                DB::statement('SET ROLE gestia_app');
            }
        }
    }

    /**
     * Particiones mensuales de una tabla de registro (nombre => primer día del mes, UTC).
     *
     * @return array<string, CarbonImmutable>
     */
    public function monthlyPartitions(string $parent): array
    {
        $rows = DB::select('SELECT c.relname FROM pg_inherits i JOIN pg_class c ON c.oid = i.inhrelid WHERE i.inhparent = ?::regclass ORDER BY c.relname', [$parent]);
        $out = [];
        foreach ($rows as $row) {
            if (preg_match('/^'.preg_quote($parent, '/').'_p(\d{4})(\d{2})$/', (string) $row->relname, $m) === 1) {
                $month = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');
                if ($month !== null) {
                    $out[(string) $row->relname] = $month;
                }
            }
        }

        return $out;
    }

    /** DETACH + DROP de una partición mensual (nunca DELETE: decisión A-06). */
    public function dropPartition(string $parent, string $partition): void
    {
        if (TenancyScope::current() !== null) {
            throw new \RuntimeException('Retirar particiones es una tarea de plataforma.');
        }
        DB::transaction(function () use ($parent, $partition): void {
            DB::statement(sprintf('ALTER TABLE %s DETACH PARTITION %s', $this->ident($parent), $this->ident($partition)));
            DB::statement(sprintf('DROP TABLE %s', $this->ident($partition)));
        });
    }

    private function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
