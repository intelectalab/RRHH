<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WFL-5 · Reglas de automatización: protección contra bucles (RN-WFL-11) y reintentos (RN-WFL-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('automatizaciones.max_ejecuciones_hora', 'base', 'Ejecuciones por hora de una regla', 'Ejecuciones de una misma regla en una hora a partir de las cuales se pausa sola y se avisa al administrador (protección contra bucles, RN-WFL-11).', 'integer', '100'::jsonb, ARRAY['organization'], '{"min": 10, "max": 10000}'::jsonb),
              ('automatizaciones.reintentos', 'base', 'Reintentos de una acción fallida', 'Veces que se reintenta automáticamente una ejecución fallida antes de dejarla para revisión manual (RN-WFL-10).', 'integer', '3'::jsonb, ARRAY['organization'], '{"min": 0, "max": 10}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
