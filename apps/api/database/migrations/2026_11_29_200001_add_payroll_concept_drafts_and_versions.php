<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CPT-1 a CPT-4 · Conceptos de planilla: borrador por concepto (payroll_concept_drafts), condición, dependencias
 * y autoría en cada versión, sustento de afectaciones (RN-CPT-06), marca del paquete de país y retiro en el
 * concepto, categoría de trabajador en las reglas de contabilización y el parámetro de doble control (RN-CPT-07).
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
            ALTER TABLE payroll_concepts ADD COLUMN IF NOT EXISTS pack_version text;
            ALTER TABLE payroll_concepts ADD COLUMN IF NOT EXISTS statutory_code_verified boolean NOT NULL DEFAULT false;
            ALTER TABLE payroll_concepts ADD COLUMN IF NOT EXISTS created_by_user_id uuid;
            ALTER TABLE payroll_concepts ADD COLUMN IF NOT EXISTS archived_at timestamptz;
            ALTER TABLE payroll_concepts ADD COLUMN IF NOT EXISTS archive_reason text;
            CREATE INDEX IF NOT EXISTS ix_payroll_concepts_created_by_user_id ON payroll_concepts (tenant_id, created_by_user_id);

            ALTER TABLE payroll_concept_versions ADD COLUMN IF NOT EXISTS condition text;
            ALTER TABLE payroll_concept_versions ADD COLUMN IF NOT EXISTS depends_on text[];
            ALTER TABLE payroll_concept_versions ADD COLUMN IF NOT EXISTS edited_by_user_id uuid;
            ALTER TABLE payroll_concept_versions ADD COLUMN IF NOT EXISTS published_by_user_id uuid;
            ALTER TABLE payroll_concept_versions ADD COLUMN IF NOT EXISTS published_at timestamptz;
            CREATE INDEX IF NOT EXISTS ix_payroll_concept_versions_edited_by_user_id ON payroll_concept_versions (tenant_id, edited_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_concept_versions_published_by_user_id ON payroll_concept_versions (tenant_id, published_by_user_id);

            ALTER TABLE payroll_concept_affectations ADD COLUMN IF NOT EXISTS justification text;
            ALTER TABLE concept_account_mappings ADD COLUMN IF NOT EXISTS worker_category text;

            CREATE TABLE IF NOT EXISTS payroll_concept_drafts (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              payroll_concept_id uuid NOT NULL,
              formula text,
              condition text,
              rounding text NOT NULL DEFAULT 'half_up' CHECK (rounding IN ('none','half_up','down','up')),
              applies_to_regimes text[],
              applies_to_period_kinds text[],
              affectations jsonb NOT NULL DEFAULT '[]'::jsonb,
              valid_from date NOT NULL,
              notes text,
              validation jsonb,
              edited_by_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_payroll_concept_drafts_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_payroll_concept_drafts_payroll_concept_id UNIQUE (tenant_id, payroll_concept_id),
              CONSTRAINT fk_payroll_concept_drafts_payroll_concept_id FOREIGN KEY (tenant_id, payroll_concept_id) REFERENCES payroll_concepts (tenant_id, id),
              CONSTRAINT fk_payroll_concept_drafts_edited_by_user_id FOREIGN KEY (tenant_id, edited_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE payroll_concept_drafts IS 'CPT-2 · Borrador de la próxima versión de un concepto (uno por concepto): se edita, se prueba y se publica; no entra al cálculo hasta publicarse.';
            CREATE INDEX IF NOT EXISTS ix_payroll_concept_drafts_edited_by_user_id ON payroll_concept_drafts (tenant_id, edited_by_user_id);
            ALTER TABLE payroll_concept_drafts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_concept_drafts FORCE ROW LEVEL SECURITY;

            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payroll_concepts_created_by_user_id') THEN
                ALTER TABLE payroll_concepts ADD CONSTRAINT fk_payroll_concepts_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payroll_concept_versions_edited_by_user_id') THEN
                ALTER TABLE payroll_concept_versions ADD CONSTRAINT fk_payroll_concept_versions_edited_by_user_id FOREIGN KEY (tenant_id, edited_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payroll_concept_versions_published_by_user_id') THEN
                ALTER TABLE payroll_concept_versions ADD CONSTRAINT fk_payroll_concept_versions_published_by_user_id FOREIGN KEY (tenant_id, published_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'concept_account_mappings_worker_category_check') THEN
                ALTER TABLE concept_account_mappings ADD CONSTRAINT concept_account_mappings_worker_category_check CHECK (worker_category IN ('executive','employee','worker'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_concept_drafts' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_concept_drafts USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_concept_drafts_touch') THEN
                CREATE TRIGGER trg_payroll_concept_drafts_touch BEFORE UPDATE ON payroll_concept_drafts FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_concept_drafts TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_concept_drafts TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('nomina.doble_control_conceptos', 'payroll', 'Doble control de conceptos', 'CPT-2 · RN-CPT-07 · Quien edita un concepto no puede publicarlo: lo publica otra persona con permiso de publicar.', 'boolean', 'true'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        // Ayuda de las pantallas nuevas (solo crea lo que falta).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
