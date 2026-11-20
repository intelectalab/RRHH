<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NOM-8 · Avance de una corrida de planilla en segundo plano: alcance resuelto (para reanudar sin cambiarlo),
 * relaciones totales, calculadas y con error, y motivo si la corrida falló. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS scope jsonb');
        DB::statement('ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS employments_total integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS employments_done integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS employments_failed integer NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS failure_reason text');
    }

    public function down(): void
    {
        foreach (['failure_reason', 'employments_failed', 'employments_done', 'employments_total', 'scope'] as $column) {
            DB::statement("ALTER TABLE payroll_runs DROP COLUMN IF EXISTS {$column}");
        }
    }
};
