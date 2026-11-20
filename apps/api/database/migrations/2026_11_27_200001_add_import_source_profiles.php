<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP-7 · Perfiles de origen versionados en import_templates: código del perfil, versión, equivalencias
 * de códigos, valores por defecto, descripción y autor de cada versión. Una versión nueva es una fila
 * nueva; la combinación (organización, perfil, tipo de datos, versión) es única.
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
            ALTER TABLE import_templates ADD COLUMN IF NOT EXISTS source_code text;
            ALTER TABLE import_templates ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 1;
            ALTER TABLE import_templates ADD COLUMN IF NOT EXISTS value_maps jsonb;
            ALTER TABLE import_templates ADD COLUMN IF NOT EXISTS default_values jsonb;
            ALTER TABLE import_templates ADD COLUMN IF NOT EXISTS description text;
            ALTER TABLE import_templates ADD COLUMN IF NOT EXISTS created_by_user_id uuid;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_import_templates_created_by_user_id') THEN
                ALTER TABLE import_templates ADD CONSTRAINT fk_import_templates_created_by_user_id
                  FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE UNIQUE INDEX IF NOT EXISTS uq_import_templates_source_code_entity_version
              ON import_templates (tenant_id, source_code, entity, version) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS ix_import_templates_created_by_user_id ON import_templates (tenant_id, created_by_user_id);
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS uq_import_templates_source_code_entity_version;
            DROP INDEX IF EXISTS ix_import_templates_created_by_user_id;
            ALTER TABLE import_templates DROP CONSTRAINT IF EXISTS fk_import_templates_created_by_user_id;
            ALTER TABLE import_templates DROP COLUMN IF EXISTS created_by_user_id;
            ALTER TABLE import_templates DROP COLUMN IF EXISTS description;
            ALTER TABLE import_templates DROP COLUMN IF EXISTS default_values;
            ALTER TABLE import_templates DROP COLUMN IF EXISTS value_maps;
            ALTER TABLE import_templates DROP COLUMN IF EXISTS version;
            ALTER TABLE import_templates DROP COLUMN IF EXISTS source_code;
            SQL);
    }
};
