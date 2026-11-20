<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS-5 · CON-6 · Escenarios de planificación y acuerdo de teletrabajo sobre las tablas existentes.
 *
 * - headcount_scenarios: empresa, fecha de la dotación de base, estado, autor, aprobación y resultado de aplicar;
 *   pasa a control de versión (lock_version).
 * - headcount_plan_lines: posición real de la línea, tipo de cambio (baseline, add, freeze, remove), título y
 *   solicitud de posición creada al aplicar.
 * - remote_work_agreements: estado «en firma», quién lo pide, plazo de respuesta, decisión, firma y término.
 * - Permisos contracts.remote_work.* y el parámetro teletrabajo.aviso_respuesta_dias.
 *
 * Idempotente: se puede correr sobre el esquema anterior o sobre uno ya generado con estas columnas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE headcount_scenarios
              ADD COLUMN IF NOT EXISTS legal_entity_id uuid,
              ADD COLUMN IF NOT EXISTS base_as_of date,
              ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','in_review','approved','applied','archived')),
              ADD COLUMN IF NOT EXISTS created_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS approved_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS approved_at timestamptz,
              ADD COLUMN IF NOT EXISTS applied_at timestamptz,
              ADD COLUMN IF NOT EXISTS applied_summary jsonb,
              ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 1;
            UPDATE headcount_scenarios SET base_as_of = created_at::date WHERE base_as_of IS NULL;
            ALTER TABLE headcount_scenarios ALTER COLUMN base_as_of SET NOT NULL;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_headcount_scenarios_legal_entity_id') THEN
                ALTER TABLE headcount_scenarios ADD CONSTRAINT fk_headcount_scenarios_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_headcount_scenarios_created_by_user_id') THEN
                ALTER TABLE headcount_scenarios ADD CONSTRAINT fk_headcount_scenarios_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_headcount_scenarios_approved_by_user_id') THEN
                ALTER TABLE headcount_scenarios ADD CONSTRAINT fk_headcount_scenarios_approved_by_user_id FOREIGN KEY (tenant_id, approved_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_headcount_scenarios_legal_entity_id ON headcount_scenarios (tenant_id, legal_entity_id);
            CREATE INDEX IF NOT EXISTS ix_headcount_scenarios_created_by_user_id ON headcount_scenarios (tenant_id, created_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_headcount_scenarios_approved_by_user_id ON headcount_scenarios (tenant_id, approved_by_user_id);
            DROP TRIGGER IF EXISTS trg_headcount_scenarios_touch ON headcount_scenarios;
            CREATE TRIGGER trg_headcount_scenarios_touch BEFORE UPDATE ON headcount_scenarios FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();

            ALTER TABLE headcount_plan_lines
              ADD COLUMN IF NOT EXISTS position_id uuid,
              ADD COLUMN IF NOT EXISTS change text NOT NULL DEFAULT 'add' CHECK (change IN ('baseline','add','freeze','remove')),
              ADD COLUMN IF NOT EXISTS title text,
              ADD COLUMN IF NOT EXISTS position_request_id uuid;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_headcount_plan_lines_position_id') THEN
                ALTER TABLE headcount_plan_lines ADD CONSTRAINT fk_headcount_plan_lines_position_id FOREIGN KEY (tenant_id, position_id) REFERENCES positions (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_headcount_plan_lines_position_request_id') THEN
                ALTER TABLE headcount_plan_lines ADD CONSTRAINT fk_headcount_plan_lines_position_request_id FOREIGN KEY (tenant_id, position_request_id) REFERENCES position_requests (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_headcount_plan_lines_position_id ON headcount_plan_lines (tenant_id, position_id);
            CREATE INDEX IF NOT EXISTS ix_headcount_plan_lines_position_request_id ON headcount_plan_lines (tenant_id, position_request_id);

            DO $$
            DECLARE c text;
            BEGIN
              FOR c IN SELECT conname FROM pg_constraint
                        WHERE conrelid = 'remote_work_agreements'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%status%'
              LOOP
                EXECUTE format('ALTER TABLE remote_work_agreements DROP CONSTRAINT %I', c);
              END LOOP;
            END $$;
            ALTER TABLE remote_work_agreements
              ADD CONSTRAINT remote_work_agreements_status_check CHECK (status IN ('requested','approved','rejected','pending_signature','active','ended')),
              ADD COLUMN IF NOT EXISTS request_reason text,
              ADD COLUMN IF NOT EXISTS requested_by text NOT NULL DEFAULT 'employee' CHECK (requested_by IN ('employee','employer')),
              ADD COLUMN IF NOT EXISTS requested_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS response_due_on date,
              ADD COLUMN IF NOT EXISTS decided_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS decision_reason text,
              ADD COLUMN IF NOT EXISTS signed_at timestamptz,
              ADD COLUMN IF NOT EXISTS signature_origin text CHECK (signature_origin IN ('physical_scan','electronic')),
              ADD COLUMN IF NOT EXISTS end_reason text,
              ADD COLUMN IF NOT EXISTS due_reminder_sent_at timestamptz;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_remote_work_agreements_requested_by_user_id') THEN
                ALTER TABLE remote_work_agreements ADD CONSTRAINT fk_remote_work_agreements_requested_by_user_id FOREIGN KEY (tenant_id, requested_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_remote_work_agreements_decided_by_user_id') THEN
                ALTER TABLE remote_work_agreements ADD CONSTRAINT fk_remote_work_agreements_decided_by_user_id FOREIGN KEY (tenant_id, decided_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_remote_work_agreements_requested_by_user_id ON remote_work_agreements (tenant_id, requested_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_remote_work_agreements_decided_by_user_id ON remote_work_agreements (tenant_id, decided_by_user_id);

            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('contracts.remote_work.view', 'core', 'remote_work', 'view', 'action', false, 'Ver solicitudes y acuerdos de teletrabajo'),
              ('contracts.remote_work.create', 'core', 'remote_work', 'create', 'action', false, 'Registrar solicitudes de teletrabajo'),
              ('contracts.remote_work.update', 'core', 'remote_work', 'update', 'action', false, 'Completar el contenido del acuerdo de teletrabajo'),
              ('contracts.remote_work.decide', 'core', 'remote_work', 'decide', 'action', false, 'Aprobar o rechazar solicitudes de teletrabajo'),
              ('contracts.remote_work.issue', 'core', 'remote_work', 'issue', 'action', false, 'Emitir, registrar firmado y terminar acuerdos de teletrabajo')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('admin_empresa', 'contracts.remote_work.view', 'organization'),
              ('admin_empresa', 'contracts.remote_work.create', 'organization'),
              ('admin_empresa', 'contracts.remote_work.update', 'organization'),
              ('admin_empresa', 'contracts.remote_work.decide', 'organization'),
              ('admin_empresa', 'contracts.remote_work.issue', 'organization'),
              ('rrhh', 'contracts.remote_work.view', 'organization'),
              ('rrhh', 'contracts.remote_work.create', 'organization'),
              ('rrhh', 'contracts.remote_work.update', 'organization'),
              ('rrhh', 'contracts.remote_work.decide', 'organization'),
              ('rrhh', 'contracts.remote_work.issue', 'organization'),
              ('manager', 'contracts.remote_work.view', 'own'),
              ('empleado', 'contracts.remote_work.view', 'own'),
              ('auditor', 'contracts.remote_work.view', 'organization')
            ON CONFLICT DO NOTHING;
            INSERT INTO role_permissions (tenant_id, role_id, permission_code, scope)
            SELECT r.tenant_id, r.id, srp.permission_code, srp.scope
              FROM roles r
              JOIN system_role_permissions srp ON srp.role_code = r.system_role_code
             WHERE srp.permission_code LIKE 'contracts.remote_work.%'
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id AND rp.permission_code = srp.permission_code);

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('teletrabajo.aviso_respuesta_dias', 'core', 'Aviso antes del plazo de respuesta a una solicitud de teletrabajo (días)', 'CON-6: días antes de que venza el plazo legal de respuesta (parámetro legal teletrabajo_respuesta_dias_habiles) en que se avisa a RRHH de una solicitud de teletrabajo sin responder. 0: el mismo día del vencimiento.', 'integer', '2'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0, "max": 10}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
