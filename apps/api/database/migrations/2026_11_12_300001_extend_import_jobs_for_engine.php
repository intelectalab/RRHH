<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP-1 · Motor de importación: nombre y cabeceras del archivo, tamaño de lote, resumen
 * (advertencias, huella repetida, motivo de bloqueo), valores anteriores por fila para deshacer
 * y el tipo «legal_entities» (primer importador). Parámetros RN-IMP-02 y de tamaño.
 * Idempotente: la base creada desde kit/database/schema.sql ya trae todo esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $entities = "'legal_entities','employees','contracts','vacation_balances','salaries','attendance','concepts','history','org_structure','dependents','positions'";

        DB::unprepared(<<<SQL
            ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS file_name text;
            ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS headers jsonb;
            ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS batch_size integer;
            ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS summary jsonb;
            ALTER TABLE import_jobs ADD COLUMN IF NOT EXISTS error_message text;
            ALTER TABLE import_jobs DROP CONSTRAINT IF EXISTS import_jobs_entity_check;
            ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_entity_check CHECK (entity IN ({$entities}));
            ALTER TABLE import_templates DROP CONSTRAINT IF EXISTS import_templates_entity_check;
            ALTER TABLE import_templates ADD CONSTRAINT import_templates_entity_check CHECK (entity IN ({$entities}));

            ALTER TABLE import_job_rows ADD COLUMN IF NOT EXISTS warnings jsonb;
            ALTER TABLE import_job_rows ADD COLUMN IF NOT EXISTS previous_data jsonb;
            ALTER TABLE import_job_rows ADD COLUMN IF NOT EXISTS batch_number integer;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('importacion.politica_errores', 'core', 'Filas con error al importar', 'Si una importación tiene filas con error: importar solo las válidas (las demás se descargan con su motivo) o exigir que se corrija todo antes (RN-IMP-02).', 'select', '"only_valid"'::jsonb, ARRAY['organization'], '["only_valid", "fix_all"]'::jsonb),
              ('importacion.tamano_lote', 'core', 'Filas por lote de importación', 'Filas que se confirman juntas en una transacción; si un lote falla, se deshace la importación completa.', 'integer', '500'::jsonb, ARRAY['organization'], '{"min": 50, "max": 5000}'::jsonb),
              ('importacion.max_filas', 'core', 'Filas máximas por archivo', 'Filas que admite un archivo de importación; los archivos más grandes se dividen.', 'integer', '50000'::jsonb, ARRAY['organization'], '{"min": 100, "max": 200000}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
