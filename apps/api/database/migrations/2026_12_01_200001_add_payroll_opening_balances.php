<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PER-12 · Acumulados del ejercicio traídos del sistema anterior (payroll_opening_balances) y su tipo de
 * importación (payroll_balances). PER-11 · Ayuda de la pantalla del libro de quinta. Idempotente.
 */
return new class extends Migration
{
    private const ENTITIES = "'legal_entities','employees','contracts','vacation_balances','salaries','attendance','concepts','history','org_structure','dependents','positions','job_profiles','payroll_incidents','payroll_balances'";

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
            CREATE TABLE IF NOT EXISTS payroll_opening_balances (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              employment_id uuid NOT NULL,
              fiscal_year smallint NOT NULL,
              cutoff_date date NOT NULL,
              period_month smallint,
              kind text NOT NULL CHECK (kind IN ('fifth_income','fifth_withheld','pension_contribution','health_contribution','gratification_base','cts_base','cts_deposit')),
              semester smallint,
              concept_code text,
              amount numeric(14,2) NOT NULL,
              currency char(3) NOT NULL,
              evidence text NOT NULL,
              import_job_id uuid,
              created_by_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_payroll_opening_balances_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_payroll_opening_balances_1 CHECK (period_month IS NULL OR period_month BETWEEN 1 AND 12),
              CONSTRAINT ck_payroll_opening_balances_2 CHECK (semester IS NULL OR semester IN (1, 2)),
              CONSTRAINT fk_payroll_opening_balances_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_payroll_opening_balances_import_job_id FOREIGN KEY (tenant_id, import_job_id) REFERENCES import_jobs (tenant_id, id),
              CONSTRAINT fk_payroll_opening_balances_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE payroll_opening_balances IS 'PER-12 · Acumulados del ejercicio traídos del sistema anterior: saldo de apertura por trabajador con fecha de corte y sustento. -- SENSIBLE: acceso por permiso de campo y registrado en data_access_logs.';
            CREATE INDEX IF NOT EXISTS ix_payroll_opening_balances_import_job_id ON payroll_opening_balances (tenant_id, import_job_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_opening_balances_created_by_user_id ON payroll_opening_balances (tenant_id, created_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payroll_opening_balances_employment_id_fiscal_year ON payroll_opening_balances (tenant_id, employment_id, fiscal_year);
            ALTER TABLE payroll_opening_balances ENABLE ROW LEVEL SECURITY;
            ALTER TABLE payroll_opening_balances FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'payroll_opening_balances' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON payroll_opening_balances
                  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_payroll_opening_balances_touch') THEN
                CREATE TRIGGER trg_payroll_opening_balances_touch BEFORE UPDATE ON payroll_opening_balances FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_opening_balances TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON payroll_opening_balances TO gestia_platform;
              END IF;
            END $$;
            SQL);

        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void
    {
        // Datos laborales: no se borran en la reversión.
    }
};
