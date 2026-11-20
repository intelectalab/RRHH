<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAS-8 · SAS-9 · SAS-12 · SAS-13 · Consola: soporte auditado, monitoreo, copias y configuración global.
 *
 * - support_sessions (plataforma): sesión de soporte con motivo, usuario suplantado, vencimiento y enlace de un solo uso.
 * - support_access_grants: la autorización del cliente puede ser para cualquier agente (platform_user_id opcional)
 *   y registra quién la revocó.
 * - backup_restores: restauración de prueba programada (kind), autor opcional (la programada no tiene), fecha de
 *   aprobación y evidencia de la verificación.
 * - platform_notices: avisos de mantenimiento para todas las organizaciones o las seleccionadas.
 * - Permisos platform.settings.view, platform.health.manage y platform.logs.view; parámetro seguridad.acceso_soporte.
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
            CREATE TABLE IF NOT EXISTS support_sessions (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL,
              platform_user_id uuid NOT NULL,
              agent_name text NOT NULL,
              target_user_id uuid NOT NULL,
              reason text NOT NULL,
              ticket_reference text,
              status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','active','ended','expired')),
              allow_sensitive boolean NOT NULL DEFAULT false,
              support_access_grant_id uuid,
              entry_token_hash text,
              entry_used_at timestamptz,
              user_session_id uuid,
              expires_at timestamptz NOT NULL,
              started_at timestamptz,
              ended_at timestamptz,
              ended_by_platform_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_support_sessions_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (id),
              CONSTRAINT fk_support_sessions_platform_user_id FOREIGN KEY (platform_user_id) REFERENCES platform_users (id),
              CONSTRAINT fk_support_sessions_ended_by_platform_user_id FOREIGN KEY (ended_by_platform_user_id) REFERENCES platform_users (id)
            );
            COMMENT ON TABLE support_sessions IS 'Sesiones de soporte con suplantación (SAS-8, RN-SAS-08): motivo, usuario suplantado, vencimiento propio, ingreso por enlace de un solo uso y marca «soporte» en la auditoría de la organización. Sin campos sensibles salvo autorización «full» vigente del cliente.';
            ALTER TABLE support_sessions ADD COLUMN IF NOT EXISTS agent_name text NOT NULL DEFAULT '';
            ALTER TABLE support_sessions ALTER COLUMN agent_name DROP DEFAULT;
            CREATE INDEX IF NOT EXISTS ix_support_sessions_platform_user_id ON support_sessions (platform_user_id);
            CREATE INDEX IF NOT EXISTS ix_support_sessions_ended_by_platform_user_id ON support_sessions (ended_by_platform_user_id);
            CREATE INDEX IF NOT EXISTS ix_support_sessions_tenant_id_expires_at ON support_sessions (tenant_id, expires_at);
            CREATE INDEX IF NOT EXISTS ix_support_sessions_user_session_id ON support_sessions (user_session_id);
            DROP TRIGGER IF EXISTS trg_support_sessions_touch ON support_sessions;
            CREATE TRIGGER trg_support_sessions_touch BEFORE UPDATE ON support_sessions FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            ALTER TABLE support_access_grants ALTER COLUMN platform_user_id DROP NOT NULL;
            ALTER TABLE support_access_grants ADD COLUMN IF NOT EXISTS revoked_by_user_id uuid;
            CREATE INDEX IF NOT EXISTS ix_support_access_grants_revoked_by_user_id ON support_access_grants (tenant_id, revoked_by_user_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_support_access_grants_revoked_by_user_id') THEN
                ALTER TABLE support_access_grants ADD CONSTRAINT fk_support_access_grants_revoked_by_user_id FOREIGN KEY (tenant_id, revoked_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;

            ALTER TABLE backup_restores ALTER COLUMN requested_by_platform_user_id DROP NOT NULL;
            ALTER TABLE backup_restores
              ADD COLUMN IF NOT EXISTS kind text NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual','scheduled_test')),
              ADD COLUMN IF NOT EXISTS approved_at timestamptz,
              ADD COLUMN IF NOT EXISTS verification jsonb;

            CREATE TABLE IF NOT EXISTS platform_notices (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              title text NOT NULL,
              message text NOT NULL,
              severity text NOT NULL DEFAULT 'info' CHECK (severity IN ('info','warning','critical')),
              audience text NOT NULL DEFAULT 'all' CHECK (audience IN ('all','selected')),
              tenant_ids jsonb,
              starts_at timestamptz NOT NULL,
              ends_at timestamptz,
              cancelled_at timestamptz,
              created_by_platform_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_platform_notices_created_by_platform_user_id FOREIGN KEY (created_by_platform_user_id) REFERENCES platform_users (id)
            );
            COMMENT ON TABLE platform_notices IS 'Avisos de la plataforma (mantenimiento, incidencias) para todas las organizaciones o las seleccionadas, con vigencia (SAS-13).';
            CREATE INDEX IF NOT EXISTS ix_platform_notices_created_by_platform_user_id ON platform_notices (created_by_platform_user_id);
            CREATE INDEX IF NOT EXISTS ix_platform_notices_starts_at ON platform_notices (starts_at);
            DROP TRIGGER IF EXISTS trg_platform_notices_touch ON platform_notices;
            CREATE TRIGGER trg_platform_notices_touch BEFORE UPDATE ON platform_notices FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            GRANT SELECT, INSERT, UPDATE, DELETE ON support_sessions, platform_notices TO gestia_app, gestia_platform;

            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('platform.settings.view', 'base', 'settings', 'view', 'action', false, 'Ver configuraciones globales y avisos de la plataforma'),
              ('platform.health.manage', 'base', 'health', 'manage', 'action', false, 'Reintentar trabajos y eventos fallidos desde el monitoreo'),
              ('platform.logs.view', 'base', 'logs', 'view', 'action', false, 'Ver la actividad y los logs técnicos de la plataforma (sin datos de RRHH)')
            ON CONFLICT (code) DO NOTHING;
            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('superadmin_saas', 'platform.settings.view', 'platform'),
              ('superadmin_saas', 'platform.health.manage', 'platform'),
              ('superadmin_saas', 'platform.logs.view', 'platform')
            ON CONFLICT DO NOTHING;
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('seguridad.acceso_soporte', 'base', 'Acceso del soporte de GestiaHR', 'Permite que el soporte de GestiaHR entre a la organización con motivo registrado y por tiempo limitado (RN-SAS-08). Desactivado, ninguna sesión de soporte puede abrirse.', 'boolean', 'true'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
