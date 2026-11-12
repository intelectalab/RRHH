<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAS-3 · Los planes se versionan: editar un plan con suscriptores crea otra fila con el mismo código y
 * la siguiente versión; la anterior queda inactiva y apunta a la nueva (superseded_by_plan_id), y sus
 * suscriptores conservan sus condiciones (RN-SAS-11).
 *
 * SAS-4 · Una sola suscripción por organización (la fuente de lo que puede usar) y la marca de cuándo
 * venció la prueba sin conversión (desde ahí corre el periodo de gracia, RN-SAS-04).
 *
 * Idempotente: sobre una base cargada con el schema.sql nuevo no cambia nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE plans ADD COLUMN IF NOT EXISTS version smallint NOT NULL DEFAULT 1');
        DB::statement('ALTER TABLE plans ADD COLUMN IF NOT EXISTS superseded_by_plan_id uuid');
        DB::statement(<<<'SQL'
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_plans_superseded_by_plan_id') THEN
                ALTER TABLE plans ADD CONSTRAINT fk_plans_superseded_by_plan_id FOREIGN KEY (superseded_by_plan_id) REFERENCES plans (id);
              END IF;
            END $$
        SQL);
        DB::statement('DROP INDEX IF EXISTS uq_plans_code');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_plans_code_version ON plans (code, version) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS ix_plans_superseded_by_plan_id ON plans (superseded_by_plan_id)');

        DB::statement('ALTER TABLE subscriptions ADD COLUMN IF NOT EXISTS expired_at timestamptz');
        DB::statement(<<<'SQL'
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_subscriptions_tenant_id') THEN
                ALTER TABLE subscriptions ADD CONSTRAINT uq_subscriptions_tenant_id UNIQUE (tenant_id);
              END IF;
            END $$
        SQL);
        // La restricción única ya indexa tenant_id.
        DB::statement('DROP INDEX IF EXISTS ix_subscriptions_tenant_id');

        // La aplicación sabe si su prueba venció (solo lectura) por la única vista que ve de tenants.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW current_tenant AS
              SELECT id, slug, name, status, country_code, default_currency, default_locale, timezone, is_demo, trial_ends_at
                FROM tenants
               WHERE id = NULLIF(current_setting('app.tenant_id', true), '')::uuid AND deleted_at IS NULL
        SQL);
        DB::statement('GRANT SELECT ON current_tenant TO gestia_app');
    }

    public function down(): void
    {
        // Sin vuelta atrás: perder la versión de un plan rompería las condiciones de sus suscriptores.
    }
};
