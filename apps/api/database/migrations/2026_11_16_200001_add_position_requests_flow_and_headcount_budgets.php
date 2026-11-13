<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS-3 · POS-4 · Solicitudes de posición con aprobación (empresa, nombre, centro de costo, posición jefe,
 * banda, marca de fuera de presupuesto y fechas de envío y decisión; las posiciones que crea guardan su
 * solicitud), presupuesto de dotación por unidad con vigencia, parámetro de cargas para el costo
 * proyectado y permiso para ver solicitudes.
 * kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS legal_entity_id uuid;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS title text;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS cost_center_id uuid;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS reports_to_position_id uuid;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS salary_band_id uuid;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS over_budget boolean NOT NULL DEFAULT false;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS decided_at timestamptz;
            ALTER TABLE position_requests ADD COLUMN IF NOT EXISTS decision_reason text;
            CREATE INDEX IF NOT EXISTS ix_position_requests_legal_entity_id ON position_requests (tenant_id, legal_entity_id);
            CREATE INDEX IF NOT EXISTS ix_position_requests_cost_center_id ON position_requests (tenant_id, cost_center_id);
            CREATE INDEX IF NOT EXISTS ix_position_requests_reports_to_position_id ON position_requests (tenant_id, reports_to_position_id);
            CREATE INDEX IF NOT EXISTS ix_position_requests_salary_band_id ON position_requests (tenant_id, salary_band_id);
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS position_request_id uuid;
            CREATE INDEX IF NOT EXISTS ix_positions_position_request_id ON positions (tenant_id, position_request_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_position_requests_legal_entity_id') THEN
                ALTER TABLE position_requests ADD CONSTRAINT fk_position_requests_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_position_requests_cost_center_id') THEN
                ALTER TABLE position_requests ADD CONSTRAINT fk_position_requests_cost_center_id FOREIGN KEY (tenant_id, cost_center_id) REFERENCES cost_centers (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_position_requests_reports_to_position_id') THEN
                ALTER TABLE position_requests ADD CONSTRAINT fk_position_requests_reports_to_position_id FOREIGN KEY (tenant_id, reports_to_position_id) REFERENCES positions (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_position_requests_salary_band_id') THEN
                ALTER TABLE position_requests ADD CONSTRAINT fk_position_requests_salary_band_id FOREIGN KEY (tenant_id, salary_band_id) REFERENCES salary_bands (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_position_requests_1') THEN
                ALTER TABLE position_requests ADD CONSTRAINT ck_position_requests_1 CHECK (quantity >= 1);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_positions_position_request_id') THEN
                ALTER TABLE positions ADD CONSTRAINT fk_positions_position_request_id FOREIGN KEY (tenant_id, position_request_id) REFERENCES position_requests (tenant_id, id);
              END IF;
            END $$;
            COMMENT ON TABLE position_requests IS 'Solicitud de posición (nueva, reemplazo o temporal) sujeta a aprobación antes de reclutar (POS-3, ME-04). Al aprobarse por el motor de aprobaciones crea sus posiciones en «aprobada» (positions.position_request_id). over_budget: al enviarla superaba el presupuesto disponible de la unidad (RN-POS-05).';

            CREATE TABLE IF NOT EXISTS headcount_budgets (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              legal_entity_id uuid NOT NULL,
              org_unit_id uuid NOT NULL,
              budgeted_positions integer NOT NULL,
              monthly_cost numeric(14,2),
              currency char(3),
              notes text,
              valid_from date NOT NULL,
              valid_to date,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_headcount_budgets_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_headcount_budgets_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ck_headcount_budgets_1 CHECK (budgeted_positions >= 0),
              CONSTRAINT ex_headcount_budgets_vigencia EXCLUDE USING gist (tenant_id WITH =, org_unit_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) WHERE (deleted_at IS NULL) DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT fk_headcount_budgets_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id),
              CONSTRAINT fk_headcount_budgets_org_unit_id FOREIGN KEY (tenant_id, org_unit_id) REFERENCES org_units (tenant_id, id)
            );
            COMMENT ON TABLE headcount_budgets IS 'Presupuesto de dotación por unidad y periodo (POS-4): plazas y costo mensual presupuestados. Un solo presupuesto vigente por unidad a la vez (valid_from / valid_to); el tablero compara contra él las posiciones aprobadas, ocupadas, vacantes y congeladas.';
            CREATE INDEX IF NOT EXISTS ix_headcount_budgets_legal_entity_id ON headcount_budgets (tenant_id, legal_entity_id);
            ALTER TABLE headcount_budgets ENABLE ROW LEVEL SECURITY;
            ALTER TABLE headcount_budgets FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON headcount_budgets;
            CREATE POLICY tenant_isolation ON headcount_budgets USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            DROP TRIGGER IF EXISTS trg_headcount_budgets_touch ON headcount_budgets;
            CREATE TRIGGER trg_headcount_budgets_touch BEFORE UPDATE ON headcount_budgets FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON headcount_budgets TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON headcount_budgets TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('posiciones.factor_carga_pct', 'core', 'Cargas del empleador para el costo proyectado (%)', 'Porcentaje que se suma al sueldo vigente o al punto medio de la banda para estimar el costo mensual de una posición (aportes del empleador, gratificaciones, CTS). Es una estimación de la organización, por validar con planilla; 0: el costo proyectado es solo el sueldo (RN-POS-07).', 'decimal', '0'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0, "max": 200}'::jsonb)
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('org.position_requests.view', 'core', 'position_requests', 'view', 'action', false, 'Ver solicitudes de posición (las propias; todas con `org.positions.approve`)')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('admin_empresa', 'org.position_requests.view', 'organization'),
              ('rrhh', 'org.position_requests.view', 'organization'),
              ('manager', 'org.position_requests.view', 'team'),
              ('auditor', 'org.position_requests.view', 'organization'),
              ('contador', 'org.position_requests.view', 'organization')
            ON CONFLICT DO NOTHING;

            -- Organizaciones existentes: los roles de sistema reciben el permiso nuevo.
            INSERT INTO role_permissions (tenant_id, role_id, permission_code, scope)
            SELECT r.tenant_id, r.id, srp.permission_code, srp.scope
              FROM roles r
              JOIN system_role_permissions srp ON srp.role_code = r.system_role_code
             WHERE srp.permission_code = 'org.position_requests.view'
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id AND rp.permission_code = srp.permission_code);
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: guarda solicitudes y presupuestos reales.
    }
};
