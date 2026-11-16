<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ESS-4 · ESS-5 · Constancias y solicitudes a RRHH desde el portal.
 *
 * - certificate_requests: la constancia de vacaciones, quién la pidió, su aprobación (workflow_instances),
 *   quién la decidió, el motivo del rechazo y si se emitió sola (constancias.emision_automatica).
 * - ticket_categories: descripción, adjunto obligatorio, orden y archivo (tipos configurables).
 * - ticket_messages: el hito del historial (creada, tomada, información pedida…) y el adjunto.
 * - Las solicitudes a RRHH pasan al módulo core (en el corte comercial; la mesa de ayuda las amplía después).
 * - Permisos people.certificates.view / issue y el parámetro constancias.emision_automatica.
 *
 * database/schema.sql trae todo para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE certificate_requests DROP CONSTRAINT IF EXISTS certificate_requests_kind_check;
            ALTER TABLE certificate_requests ADD CONSTRAINT certificate_requests_kind_check
              CHECK (kind IN ('employment_certificate','income_certificate','vacation_certificate','cts_letter','withholding_certificate','other'));
            ALTER TABLE certificate_requests
              ADD COLUMN IF NOT EXISTS requested_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS workflow_instance_id uuid,
              ADD COLUMN IF NOT EXISTS decided_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS decided_at timestamptz,
              ADD COLUMN IF NOT EXISTS rejection_reason text,
              ADD COLUMN IF NOT EXISTS auto_issued boolean NOT NULL DEFAULT false;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_certificate_requests_requested_by_user_id') THEN
                ALTER TABLE certificate_requests ADD CONSTRAINT fk_certificate_requests_requested_by_user_id FOREIGN KEY (tenant_id, requested_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_certificate_requests_workflow_instance_id') THEN
                ALTER TABLE certificate_requests ADD CONSTRAINT fk_certificate_requests_workflow_instance_id FOREIGN KEY (tenant_id, workflow_instance_id) REFERENCES workflow_instances (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_certificate_requests_decided_by_user_id') THEN
                ALTER TABLE certificate_requests ADD CONSTRAINT fk_certificate_requests_decided_by_user_id FOREIGN KEY (tenant_id, decided_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_ticket_messages_stored_file_id') THEN
                ALTER TABLE ticket_messages ADD COLUMN IF NOT EXISTS stored_file_id uuid;
                ALTER TABLE ticket_messages ADD CONSTRAINT fk_ticket_messages_stored_file_id FOREIGN KEY (tenant_id, stored_file_id) REFERENCES stored_files (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_certificate_requests_requested_by_user_id ON certificate_requests (tenant_id, requested_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_certificate_requests_workflow_instance_id ON certificate_requests (tenant_id, workflow_instance_id);
            CREATE INDEX IF NOT EXISTS ix_certificate_requests_decided_by_user_id ON certificate_requests (tenant_id, decided_by_user_id);

            ALTER TABLE ticket_categories
              ADD COLUMN IF NOT EXISTS description text,
              ADD COLUMN IF NOT EXISTS requires_attachment boolean NOT NULL DEFAULT false,
              ADD COLUMN IF NOT EXISTS sort_order integer NOT NULL DEFAULT 100,
              ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived'));
            ALTER TABLE ticket_messages
              ADD COLUMN IF NOT EXISTS event text NOT NULL DEFAULT 'comment' CHECK (event IN ('created','assigned','info_requested','replied','resolved','reopened','closed','comment')),
              ADD COLUMN IF NOT EXISTS file_name text;
            CREATE INDEX IF NOT EXISTS ix_ticket_messages_stored_file_id ON ticket_messages (tenant_id, stored_file_id);

            UPDATE permissions SET module_code = 'core' WHERE code LIKE 'helpdesk.tickets.%' OR code = 'helpdesk.settings.update';
            UPDATE permissions SET description = 'Configurar los tipos de solicitud a RRHH (y, con la mesa de ayuda, colas, SLA y plantillas)' WHERE code = 'helpdesk.settings.update';
            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('people.certificates.view', 'core', 'certificates', 'view', 'action', false, 'Ver solicitudes de constancias'),
              ('people.certificates.issue', 'core', 'certificates', 'issue', 'action', false, 'Emitir o rechazar constancias pedidas')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('admin_empresa', 'people.certificates.view', 'organization'),
              ('rrhh', 'people.certificates.view', 'organization'),
              ('auditor', 'people.certificates.view', 'organization'),
              ('manager', 'people.certificates.view', 'own'),
              ('empleado', 'people.certificates.view', 'own'),
              ('admin_empresa', 'people.certificates.issue', 'organization'),
              ('rrhh', 'people.certificates.issue', 'organization')
            ON CONFLICT DO NOTHING;
            INSERT INTO role_permissions (tenant_id, role_id, permission_code, scope)
            SELECT r.tenant_id, r.id, srp.permission_code, srp.scope
              FROM roles r
              JOIN system_role_permissions srp ON srp.role_code = r.system_role_code
             WHERE srp.permission_code IN ('people.certificates.view', 'people.certificates.issue')
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id AND rp.permission_code = srp.permission_code);

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('constancias.emision_automatica', 'core', 'Constancias de emisión automática', 'ESS-4 · Constancias que el colaborador obtiene al instante desde el portal, sin intervención de RRHH; las demás pasan por el flujo de aprobación de constancias o las emite RRHH.', 'list', '["employment_certificate"]'::jsonb, ARRAY['organization'], '["employment_certificate", "income_certificate", "vacation_certificate"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
