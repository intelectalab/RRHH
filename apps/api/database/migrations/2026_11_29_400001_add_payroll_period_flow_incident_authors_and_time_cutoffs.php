<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PER-1 · PER-2 · PER-3 · Autores del flujo del periodo (abrió, envió, aprobó, motivos), autor y visto bueno de
 * cada incidencia, corte de tiempo de la planilla (solo inserción) y el parámetro de monto que exige visto bueno.
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
              ADD COLUMN IF NOT EXISTS opened_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS submitted_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS submitted_at timestamptz,
              ADD COLUMN IF NOT EXISTS approved_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS approved_at timestamptz,
              ADD COLUMN IF NOT EXISTS return_reason text,
              ADD COLUMN IF NOT EXISTS reopen_reason text,
              ADD COLUMN IF NOT EXISTS time_consumed_at timestamptz,
              ADD COLUMN IF NOT EXISTS time_consumed_by_user_id uuid;
            ALTER TABLE payroll_incidents
              ADD COLUMN IF NOT EXISTS created_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS updated_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS decided_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS decided_at timestamptz,
              ADD COLUMN IF NOT EXISTS decision_reason text;
            DO $$
            DECLARE r record;
            BEGIN
              FOR r IN SELECT * FROM (VALUES
                ('payroll_periods', 'opened_by_user_id'), ('payroll_periods', 'submitted_by_user_id'), ('payroll_periods', 'approved_by_user_id'),
                ('payroll_periods', 'time_consumed_by_user_id'), ('payroll_incidents', 'created_by_user_id'), ('payroll_incidents', 'updated_by_user_id'),
                ('payroll_incidents', 'decided_by_user_id')) AS t(tbl, col)
              LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_' || r.tbl || '_' || r.col) THEN
                  EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (tenant_id, %I) REFERENCES users (tenant_id, id)', r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col);
                END IF;
                EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (tenant_id, %I)', 'ix_' || r.tbl || '_' || r.col, r.tbl, r.col);
              END LOOP;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_payroll_incidents_payroll_period_id_employment_id ON payroll_incidents (tenant_id, payroll_period_id, employment_id);

            CREATE TABLE IF NOT EXISTS payroll_period_time_cutoffs (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              payroll_period_id uuid NOT NULL,
              employment_id uuid NOT NULL,
              period_from date NOT NULL,
              period_to date NOT NULL,
              fingerprint text NOT NULL,
              summary jsonb NOT NULL,
              consumed_by_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_payroll_period_time_cutoffs_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_payroll_period_time_cutoffs_payroll_period_id FOREIGN KEY (tenant_id, payroll_period_id) REFERENCES payroll_periods (tenant_id, id),
              CONSTRAINT fk_payroll_period_time_cutoffs_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_payroll_period_time_cutoffs_consumed_by_user_id FOREIGN KEY (tenant_id, consumed_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE payroll_period_time_cutoffs IS 'PER-3 · Corte de tiempo de un trabajador para un periodo: resumen de ausencias y vacaciones tomado por la planilla (solo inserción). Permite reproducir el cálculo y detectar lo ocurrido después del corte.';
            CREATE INDEX IF NOT EXISTS ix_payroll_period_time_cutoffs_employment_id ON payroll_period_time_cutoffs (tenant_id, employment_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_period_time_cutoffs_consumed_by_user_id ON payroll_period_time_cutoffs (tenant_id, consumed_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_period_time_cutoffs_payroll_period_id_employment_id ON payroll_period_time_cutoffs (tenant_id, payroll_period_id, employment_id);
            ALTER TABLE payroll_period_time_cutoffs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_period_time_cutoffs FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_period_time_cutoffs' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_period_time_cutoffs
                  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_period_time_cutoffs_inmutable') THEN
                CREATE TRIGGER trg_payroll_period_time_cutoffs_inmutable BEFORE UPDATE OR DELETE ON payroll_period_time_cutoffs FOR EACH ROW EXECUTE FUNCTION reject_mutation();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_period_time_cutoffs_no_truncate') THEN
                CREATE TRIGGER trg_payroll_period_time_cutoffs_no_truncate BEFORE TRUNCATE ON payroll_period_time_cutoffs FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT ON payroll_period_time_cutoffs TO gestia_app;
                REVOKE UPDATE, DELETE ON payroll_period_time_cutoffs FROM gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT ON payroll_period_time_cutoffs TO gestia_platform;
                REVOKE UPDATE, DELETE ON payroll_period_time_cutoffs FROM gestia_platform;
              END IF;
            END $$;
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('nomina.incidencia_monto_visto_bueno', 'payroll', 'Monto de incidencia que requiere visto bueno', 'PER-2 · RN-PER-10: una incidencia manual cuyo monto supera este valor necesita el visto bueno de alguien con payroll.incidents.approve antes de entrar al cálculo. 0: sin visto bueno. Política de la organización, no valor legal.', 'decimal', '0'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        // Ayuda de las pantallas de planilla (solo crea lo que falta).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
