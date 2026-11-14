<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VAC-1 · VAC-2 · La política de vacaciones pasa a identidad + reglas con vigencia (vacation_policy_rules),
 * los periodos vacacionales guardan cómo se calcularon sus días y el libro de movimientos admite sustento
 * y clave de idempotencia. Agrega el permiso para ver políticas.
 *
 * kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente
 * (idempotente). La tabla vacation_policies anterior no tenía uso en la aplicación: si está vacía se
 * reemplaza por la nueva forma; si tuviera filas, la migración se detiene para no perder datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $oldShape = DB::selectOne("SELECT 1 AS x FROM information_schema.columns WHERE table_name = 'vacation_policies' AND column_name = 'days_per_year'");
        if ($oldShape !== null) {
            $rows = (int) DB::selectOne('SELECT count(*) AS n FROM vacation_policies')->n;
            if ($rows > 0) {
                throw new RuntimeException('vacation_policies tiene filas con la forma anterior: migrarlas a mano antes de continuar.');
            }
            DB::unprepared('DROP TABLE vacation_policies CASCADE;');
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS vacation_policies (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              code text NOT NULL,
              name text NOT NULL,
              legal_entity_id uuid,
              labor_regime_code text,
              job_category_id uuid,
              status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived')),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_vacation_policies_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_vacation_policies_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id),
              CONSTRAINT fk_vacation_policies_job_category_id FOREIGN KEY (tenant_id, job_category_id) REFERENCES job_categories (tenant_id, id)
            );
            COMMENT ON TABLE vacation_policies IS 'Política de vacaciones (VAC-1): identidad y alcance por empresa, régimen y categoría laboral. Las reglas viven en vacation_policy_rules con vigencia; la más específica que aplica a la relación manda.';
            CREATE UNIQUE INDEX IF NOT EXISTS uq_vacation_policies_code ON vacation_policies (tenant_id, code) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS ix_vacation_policies_legal_entity_id ON vacation_policies (tenant_id, legal_entity_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_policies_labor_regime_code ON vacation_policies (tenant_id, labor_regime_code);
            CREATE INDEX IF NOT EXISTS ix_vacation_policies_job_category_id ON vacation_policies (tenant_id, job_category_id);
            ALTER TABLE vacation_policies ENABLE ROW LEVEL SECURITY;
            ALTER TABLE vacation_policies FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON vacation_policies;
            CREATE POLICY tenant_isolation ON vacation_policies USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            DROP TRIGGER IF EXISTS trg_vacation_policies_touch ON vacation_policies;
            CREATE TRIGGER trg_vacation_policies_touch BEFORE UPDATE ON vacation_policies FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_vacation_policies_labor_regime_code') THEN
                ALTER TABLE vacation_policies ADD CONSTRAINT fk_vacation_policies_labor_regime_code FOREIGN KEY (labor_regime_code) REFERENCES labor_regimes (code);
              END IF;
            END $$;

            CREATE TABLE IF NOT EXISTS vacation_policy_rules (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              vacation_policy_id uuid NOT NULL,
              days_per_year numeric(5,2),
              counts_calendar_days boolean NOT NULL DEFAULT true,
              min_days_per_request numeric(5,2),
              allow_advance boolean NOT NULL DEFAULT false,
              max_advance_days numeric(5,2),
              allow_sale boolean NOT NULL DEFAULT false,
              max_sale_days numeric(5,2),
              max_accumulated_periods smallint,
              requires_hr_validation boolean NOT NULL DEFAULT false,
              approval_workflow_definition_id uuid,
              valid_from date NOT NULL,
              valid_to date,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_vacation_policy_rules_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_vacation_policy_rules_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ex_vacation_policy_rules_vigencia EXCLUDE USING gist (tenant_id WITH =, vacation_policy_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT fk_vacation_policy_rules_vacation_policy_id FOREIGN KEY (tenant_id, vacation_policy_id) REFERENCES vacation_policies (tenant_id, id),
              CONSTRAINT fk_vacation_policy_rules_approval_workflow_definition_id FOREIGN KEY (tenant_id, approval_workflow_definition_id) REFERENCES workflow_definitions (tenant_id, id)
            );
            COMMENT ON TABLE vacation_policy_rules IS 'Reglas de una política de vacaciones con vigencia (VAC-1): días por año (vacío = los del régimen en los parámetros legales), cómputo calendario o hábil, fraccionamiento mínimo, adelanto, venta, máximo de periodos acumulados y validación de RRHH. Cambiar una regla cierra la fila vigente e inserta otra.';
            CREATE INDEX IF NOT EXISTS ix_vacation_policy_rules_approval_workflow_definition_id ON vacation_policy_rules (tenant_id, approval_workflow_definition_id);
            ALTER TABLE vacation_policy_rules ENABLE ROW LEVEL SECURITY;
            ALTER TABLE vacation_policy_rules FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON vacation_policy_rules;
            CREATE POLICY tenant_isolation ON vacation_policy_rules USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            DROP TRIGGER IF EXISTS trg_vacation_policy_rules_touch ON vacation_policy_rules;
            CREATE TRIGGER trg_vacation_policy_rules_touch BEFORE UPDATE ON vacation_policy_rules FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();

            ALTER TABLE vacation_accrual_periods ADD COLUMN IF NOT EXISTS entitlement_detail jsonb;
            ALTER TABLE vacation_accrual_periods ADD COLUMN IF NOT EXISTS earned_on date;
            ALTER TABLE vacation_accrual_periods ADD COLUMN IF NOT EXISTS pending_validation boolean NOT NULL DEFAULT false;
            COMMENT ON TABLE vacation_accrual_periods IS 'Récord vacacional por año de servicio (VAC-2): días que corresponden (prorrateados por política y régimen vigentes en el año), fecha en que se ganan y fecha límite de goce.';
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_vacation_accrual_periods_vacation_policy_id') THEN
                ALTER TABLE vacation_accrual_periods ADD CONSTRAINT fk_vacation_accrual_periods_vacation_policy_id FOREIGN KEY (tenant_id, vacation_policy_id) REFERENCES vacation_policies (tenant_id, id);
              END IF;
            END $$;

            ALTER TABLE vacation_ledger_entries ADD COLUMN IF NOT EXISTS evidence text;
            ALTER TABLE vacation_ledger_entries ADD COLUMN IF NOT EXISTS idempotency_key text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_vacation_ledger_entries_idempotency_key') THEN
                ALTER TABLE vacation_ledger_entries ADD CONSTRAINT uq_vacation_ledger_entries_idempotency_key UNIQUE (tenant_id, idempotency_key);
              END IF;
            END $$;
            COMMENT ON TABLE vacation_ledger_entries IS 'Libro de movimientos de vacaciones, de solo inserción. El saldo es la suma de movimientos (C-11); un error se corrige con un contraasiento. Los días llevan signo: suman el devengo, el saldo inicial y los ajustes a favor; restan la reserva (programadas), el goce, la venta y la liquidación.';

            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON vacation_policies, vacation_policy_rules TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON vacation_policies, vacation_policy_rules TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('time.vacation_policies.view', 'time_off', 'vacation_policies', 'view', 'action', false, 'Ver políticas de vacaciones')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('admin_empresa', 'time.vacation_policies.view', 'organization'),
              ('rrhh', 'time.vacation_policies.view', 'organization'),
              ('payroll', 'time.vacation_policies.view', 'organization'),
              ('auditor', 'time.vacation_policies.view', 'organization')
            ON CONFLICT DO NOTHING;
            INSERT INTO role_permissions (tenant_id, role_id, permission_code, scope)
            SELECT r.tenant_id, r.id, srp.permission_code, srp.scope
              FROM roles r
              JOIN system_role_permissions srp ON srp.role_code = r.system_role_code
             WHERE srp.permission_code = 'time.vacation_policies.view'
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id AND rp.permission_code = srp.permission_code);
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: guarda políticas, periodos y movimientos reales.
    }
};
