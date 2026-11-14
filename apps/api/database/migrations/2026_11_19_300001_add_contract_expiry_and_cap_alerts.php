<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CON-4 · Alertas de vencimiento y de tope acumulado: el contrato recuerda la última anticipación avisada para
 * su fecha de fin (no repite el aviso; si la fecha cambia, vuelve a avisar), el día del aviso de tope y el
 * motivo con que se confirmó una renovación por encima del tope. Los parámetros contratos.alerta_vencimiento_dias
 * y contratos.alerta_tope_acumulado_dias ya vienen en el catálogo.
 * kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS expiry_alerted_days integer;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS expiry_alerted_for date;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS cap_alerted_on date;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS over_limit_reason text;
            SQL);
    }

    public function down(): void {}
};
