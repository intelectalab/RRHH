<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP-3 · IMP-4 · Paquete de configuración inicial del Perú (pe-base) en el catálogo de plataforma y el
 * parámetro que oculta el asistente de primeros pasos.
 *
 * database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO country_packs (country_code, code, name, status, current_version)
            SELECT 'PE', 'pe-base', 'Perú · configuración inicial', 'available', '1.0.0'
            WHERE NOT EXISTS (SELECT 1 FROM country_packs WHERE code = 'pe-base');

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('puesta_en_marcha.omitida', 'core', 'Ocultar el asistente de primeros pasos', 'IMP-3: con «sí», el asistente de primeros pasos deja de mostrarse en el inicio aunque falten pasos; se vuelve a mostrar desde Primeros pasos. Se oculta solo cuando todos los pasos están hechos u omitidos.', 'boolean', 'false'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
