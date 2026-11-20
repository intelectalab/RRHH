<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * UI-11 · RN-UI-08: umbral de filas a partir del cual una exportación se genera en segundo plano y avisa
 * cuando está lista.
 *
 * database/schema.sql lo trae para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
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
              ('exportaciones.umbral_segundo_plano', 'base', 'Filas para exportar en segundo plano', 'UI-11 · RN-UI-08: una exportación con más filas que este número se genera en segundo plano y avisa con un enlace cuando está lista; las más chicas se descargan al instante.', 'integer', '1000'::jsonb, ARRAY['organization'], '{"min": 100, "max": 50000}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
