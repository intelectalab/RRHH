<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PER-13 · Planilla en paralelo: resultados del sistema anterior importados, comparación con la corrida y
 * diferencias clasificadas. CUM-9 · Tablero de obligaciones de planilla: responsable por obligación y avisos
 * enviados (solo inserción). Parámetros de organización de ambos.
 *
 * database/schema.sql trae todo para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    private const ENTITIES = "'legal_entities','employees','contracts','vacation_balances','salaries','attendance','concepts','history','org_structure','dependents','positions','job_profiles','payroll_incidents','payroll_balances','legacy_payroll_results'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['import_jobs', 'import_templates'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_entity_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_entity_check CHECK (entity IN (".self::ENTITIES.'))');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS payroll_legacy_results (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              payroll_period_id uuid NOT NULL,
              employment_id uuid NOT NULL,
              concept_code text NOT NULL,
              amount numeric(14,2) NOT NULL,  -- SENSIBLE
              currency char(3) NOT NULL,
              import_job_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              CONSTRAINT uq_payroll_legacy_results_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_payroll_legacy_results_payroll_period_id FOREIGN KEY (tenant_id, payroll_period_id) REFERENCES payroll_periods (tenant_id, id),
              CONSTRAINT fk_payroll_legacy_results_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_payroll_legacy_results_import_job_id FOREIGN KEY (tenant_id, import_job_id) REFERENCES import_jobs (tenant_id, id)
            );
            COMMENT ON TABLE payroll_legacy_results IS 'PER-13 · Resultado del sistema anterior por trabajador y concepto, importado (perfil de origen de IMP-7) para la planilla en paralelo durante la migración. -- SENSIBLE: acceso por permiso de campo y registrado en data_access_logs.';
            CREATE INDEX IF NOT EXISTS ix_payroll_legacy_results_employment_id ON payroll_legacy_results (tenant_id, employment_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_legacy_results_import_job_id ON payroll_legacy_results (tenant_id, import_job_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_legacy_results_payroll_period_id_employment_id ON payroll_legacy_results (tenant_id, payroll_period_id, employment_id);
            ALTER TABLE payroll_legacy_results ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_legacy_results FORCE ROW LEVEL SECURITY;
            CREATE TABLE IF NOT EXISTS payroll_parallel_comparisons (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              payroll_period_id uuid NOT NULL,
              payroll_run_id uuid NOT NULL,
              legacy_system text,
              tolerance numeric(14,2) NOT NULL,
              currency char(3) NOT NULL,
              status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','ready')),
              summary jsonb,
              report_document_id uuid,
              created_by_user_id uuid,
              ready_by_user_id uuid,
              ready_at timestamptz,
              ready_note text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_payroll_parallel_comparisons_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_comparisons_payroll_period_id FOREIGN KEY (tenant_id, payroll_period_id) REFERENCES payroll_periods (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_comparisons_payroll_run_id FOREIGN KEY (tenant_id, payroll_run_id) REFERENCES payroll_runs (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_comparisons_report_document_id FOREIGN KEY (tenant_id, report_document_id) REFERENCES documents (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_comparisons_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_comparisons_ready_by_user_id FOREIGN KEY (tenant_id, ready_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE payroll_parallel_comparisons IS 'PER-13 · Comparación de una corrida del periodo con el resultado del sistema anterior: tolerancia de redondeo, estado (abierta o lista para salir), resumen e informe guardado en el legajo de la migración.';
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_comparisons_payroll_period_id ON payroll_parallel_comparisons (tenant_id, payroll_period_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_comparisons_payroll_run_id ON payroll_parallel_comparisons (tenant_id, payroll_run_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_comparisons_report_document_id ON payroll_parallel_comparisons (tenant_id, report_document_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_comparisons_created_by_user_id ON payroll_parallel_comparisons (tenant_id, created_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_comparisons_ready_by_user_id ON payroll_parallel_comparisons (tenant_id, ready_by_user_id);
            ALTER TABLE payroll_parallel_comparisons ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_parallel_comparisons FORCE ROW LEVEL SECURITY;
            CREATE TABLE IF NOT EXISTS payroll_parallel_differences (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              payroll_parallel_comparison_id uuid NOT NULL,
              employment_id uuid NOT NULL,
              concept_code text NOT NULL,
              legacy_amount numeric(14,2),  -- SENSIBLE
              product_amount numeric(14,2),  -- SENSIBLE
              difference numeric(14,2) NOT NULL,  -- SENSIBLE
              currency char(3) NOT NULL,
              classification text NOT NULL DEFAULT 'unclassified' CHECK (classification IN ('input','parameter','rule','rounding','unclassified')),
              explained boolean NOT NULL DEFAULT false,
              comment text,
              explained_by_user_id uuid,
              explained_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_payroll_parallel_differences_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_payroll_parallel_differences_payroll_parallel_compa_9180fd22 UNIQUE (tenant_id, payroll_parallel_comparison_id, employment_id, concept_code),
              CONSTRAINT fk_payroll_parallel_differences_payroll_parallel_comparison_id FOREIGN KEY (tenant_id, payroll_parallel_comparison_id) REFERENCES payroll_parallel_comparisons (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_differences_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_payroll_parallel_differences_explained_by_user_id FOREIGN KEY (tenant_id, explained_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE payroll_parallel_differences IS 'PER-13 · Diferencia por trabajador y concepto entre el sistema anterior y el producto, clasificada (dato de entrada, parámetro, regla o redondeo) y explicada con comentario. -- SENSIBLE: acceso por permiso de campo y registrado en data_access_logs.';
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_differences_employment_id ON payroll_parallel_differences (tenant_id, employment_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_parallel_differences_explained_by_user_id ON payroll_parallel_differences (tenant_id, explained_by_user_id);
            ALTER TABLE payroll_parallel_differences ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_parallel_differences FORCE ROW LEVEL SECURITY;
            CREATE TABLE IF NOT EXISTS payroll_obligation_assignments (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              legal_entity_id uuid NOT NULL,
              obligation_code text NOT NULL,
              responsible_user_id uuid NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_payroll_obligation_assignments_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_payroll_obligation_assignments_legal_entity_id_obli_f1413346 UNIQUE (tenant_id, legal_entity_id, obligation_code),
              CONSTRAINT fk_payroll_obligation_assignments_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id),
              CONSTRAINT fk_payroll_obligation_assignments_responsible_user_id FOREIGN KEY (tenant_id, responsible_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE payroll_obligation_assignments IS 'CUM-9 · Responsable de cada obligación de planilla por empresa (T-Registro, PLAME, AFPnet, CTS, gratificación, contratos por vencer, comité de SST).';
            CREATE INDEX IF NOT EXISTS ix_payroll_obligation_assignments_responsible_user_id ON payroll_obligation_assignments (tenant_id, responsible_user_id);
            ALTER TABLE payroll_obligation_assignments ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_obligation_assignments FORCE ROW LEVEL SECURITY;
            CREATE TABLE IF NOT EXISTS payroll_obligation_alerts (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              legal_entity_id uuid NOT NULL,
              obligation_code text NOT NULL,
              period_key text NOT NULL,
              kind text NOT NULL CHECK (kind IN ('before_due','overdue')),
              sent_at timestamptz NOT NULL DEFAULT now(),
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_payroll_obligation_alerts_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_payroll_obligation_alerts_legal_entity_id_obligatio_2690e191 UNIQUE (tenant_id, legal_entity_id, obligation_code, period_key, kind),
              CONSTRAINT fk_payroll_obligation_alerts_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id)
            );
            COMMENT ON TABLE payroll_obligation_alerts IS 'CUM-9 · Avisos enviados por obligación y vencimiento (antes de vencer y al vencer sin cumplir), solo inserción, para no repetirlos.';
            ALTER TABLE payroll_obligation_alerts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_obligation_alerts FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_legacy_results' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_legacy_results USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_legacy_results_touch') THEN
                CREATE TRIGGER trg_payroll_legacy_results_touch BEFORE UPDATE ON payroll_legacy_results FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_parallel_comparisons' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_parallel_comparisons USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_parallel_comparisons_touch') THEN
                CREATE TRIGGER trg_payroll_parallel_comparisons_touch BEFORE UPDATE ON payroll_parallel_comparisons FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_parallel_differences' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_parallel_differences USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_parallel_differences_touch') THEN
                CREATE TRIGGER trg_payroll_parallel_differences_touch BEFORE UPDATE ON payroll_parallel_differences FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_obligation_assignments' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_obligation_assignments USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_obligation_assignments_touch') THEN
                CREATE TRIGGER trg_payroll_obligation_assignments_touch BEFORE UPDATE ON payroll_obligation_assignments FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_obligation_alerts' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_obligation_alerts USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_obligation_alerts_inmutable') THEN
                CREATE TRIGGER trg_payroll_obligation_alerts_inmutable BEFORE UPDATE OR DELETE ON payroll_obligation_alerts FOR EACH ROW EXECUTE FUNCTION reject_mutation();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_obligation_alerts_no_truncate') THEN
                CREATE TRIGGER trg_payroll_obligation_alerts_no_truncate BEFORE TRUNCATE ON payroll_obligation_alerts FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_legacy_results TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_legacy_results TO gestia_platform;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_parallel_comparisons TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_parallel_comparisons TO gestia_platform;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_parallel_differences TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_parallel_differences TO gestia_platform;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_obligation_assignments TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_obligation_assignments TO gestia_platform;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT ON payroll_obligation_alerts TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT ON payroll_obligation_alerts TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('nomina.paralelo_tolerancia', 'payroll', 'Tolerancia de redondeo en la planilla en paralelo', 'PER-13 · Una diferencia con el sistema anterior igual o menor que este monto se clasifica sola como redondeo.', 'decimal', '0.1'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0, "max": 100}'::jsonb),
              ('cumplimiento.aviso_dias', 'payroll', 'Aviso previo de vencimientos de planilla (días)', 'CUM-9 · Días antes del vencimiento en que se avisa al responsable de cada obligación de planilla. 0: solo al vencer.', 'integer', '5'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0, "max": 60}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void
    {
        // Datos de la migración y de cumplimiento: no se borran en la reversión.
    }
};
