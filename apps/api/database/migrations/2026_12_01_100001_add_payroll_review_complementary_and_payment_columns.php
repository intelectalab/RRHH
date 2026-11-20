<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PER-7 a PER-10 · Revisión de anomalías (concepto, causas, fecha de revisión), umbrales de variación por empresa y
 * concepto, planilla complementaria (periodo que complementa), registro del pago del periodo y datos del lote de pago
 * (formato, quién generó y confirmó, motivo de anulación).
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
            ALTER TABLE payroll_periods
              ADD COLUMN IF NOT EXISTS complements_period_id uuid,
              ADD COLUMN IF NOT EXISTS paid_at timestamptz,
              ADD COLUMN IF NOT EXISTS paid_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS payment_method text;
            ALTER TABLE payroll_run_anomalies
              ADD COLUMN IF NOT EXISTS payroll_concept_id uuid,
              ADD COLUMN IF NOT EXISTS causes jsonb,
              ADD COLUMN IF NOT EXISTS reviewed_at timestamptz;
            ALTER TABLE payroll_payment_batches
              ADD COLUMN IF NOT EXISTS file_format text,
              ADD COLUMN IF NOT EXISTS generated_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS generated_at timestamptz,
              ADD COLUMN IF NOT EXISTS confirmed_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS confirmed_at timestamptz,
              ADD COLUMN IF NOT EXISTS cancel_reason text,
              ADD COLUMN IF NOT EXISTS items_count integer NOT NULL DEFAULT 0;
            DO $$
            DECLARE r record;
            BEGIN
              FOR r IN SELECT * FROM (VALUES
                ('payroll_periods', 'complements_period_id', 'payroll_periods'), ('payroll_periods', 'paid_by_user_id', 'users'),
                ('payroll_run_anomalies', 'payroll_concept_id', 'payroll_concepts'),
                ('payroll_payment_batches', 'generated_by_user_id', 'users'), ('payroll_payment_batches', 'confirmed_by_user_id', 'users')) AS t(tbl, col, ref)
              LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_' || r.tbl || '_' || r.col) THEN
                  EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (tenant_id, %I) REFERENCES %I (tenant_id, id)', r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col, r.ref);
                END IF;
                EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (tenant_id, %I)', 'ix_' || r.tbl || '_' || r.col, r.tbl, r.col);
              END LOOP;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_payroll_run_anomalies_payroll_run_id_status ON payroll_run_anomalies (tenant_id, payroll_run_id, status);

            CREATE TABLE IF NOT EXISTS payroll_variance_thresholds (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              legal_entity_id uuid,
              payroll_concept_id uuid,
              threshold_pct numeric(7,2) NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_payroll_variance_thresholds_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_payroll_variance_thresholds_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id),
              CONSTRAINT fk_payroll_variance_thresholds_payroll_concept_id FOREIGN KEY (tenant_id, payroll_concept_id) REFERENCES payroll_concepts (tenant_id, id)
            );
            COMMENT ON TABLE payroll_variance_thresholds IS 'PER-7 · RN-PER-05 · Umbral de variación (%) contra el periodo anterior por empresa y concepto; sin concepto aplica al neto. Sin fila rige el parámetro nomina.variacion_neto_alerta_pct.';
            CREATE UNIQUE INDEX IF NOT EXISTS uq_payroll_variance_thresholds_legal_entity_id_payroll_85803080 ON payroll_variance_thresholds (tenant_id, legal_entity_id, payroll_concept_id) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS ix_payroll_variance_thresholds_payroll_concept_id ON payroll_variance_thresholds (tenant_id, payroll_concept_id);
            ALTER TABLE payroll_variance_thresholds ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_variance_thresholds FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_variance_thresholds' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_variance_thresholds
                  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_variance_thresholds_touch') THEN
                CREATE TRIGGER trg_payroll_variance_thresholds_touch BEFORE UPDATE ON payroll_variance_thresholds FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_variance_thresholds TO gestia_app;
              END IF;
            END $$;
            SQL);

        if (Schema::hasTable('help_articles') && class_exists(ProductHelpArticles::class)) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS payroll_variance_thresholds;
            ALTER TABLE payroll_payment_batches
              DROP COLUMN IF EXISTS file_format, DROP COLUMN IF EXISTS generated_by_user_id, DROP COLUMN IF EXISTS generated_at,
              DROP COLUMN IF EXISTS confirmed_by_user_id, DROP COLUMN IF EXISTS confirmed_at, DROP COLUMN IF EXISTS cancel_reason, DROP COLUMN IF EXISTS items_count;
            ALTER TABLE payroll_run_anomalies DROP COLUMN IF EXISTS payroll_concept_id, DROP COLUMN IF EXISTS causes, DROP COLUMN IF EXISTS reviewed_at;
            ALTER TABLE payroll_periods DROP COLUMN IF EXISTS complements_period_id, DROP COLUMN IF EXISTS paid_at, DROP COLUMN IF EXISTS paid_by_user_id, DROP COLUMN IF EXISTS payment_method;
            SQL);
    }
};
