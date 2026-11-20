<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CFG-5 · NOT-4 · ESS-6.
 *
 * - push_devices.label: nombre legible del navegador donde el usuario aceptó push.
 * - push pasa a los canales activos por defecto (solo sale a quien registró un dispositivo).
 * - user_quick_unlocks: reingreso rápido con PIN en el teléfono, aislado por organización (RLS forzado).
 * - Parámetros seguridad.pin_dias_vigencia y seguridad.pin_intentos.
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
            ALTER TABLE push_devices ADD COLUMN IF NOT EXISTS label text;

            CREATE TABLE IF NOT EXISTS user_quick_unlocks (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              user_id uuid NOT NULL,
              device_token_hash text NOT NULL,
              pin_hash text NOT NULL,
              device_label text,
              mfa_passed boolean NOT NULL DEFAULT false,
              failed_attempts integer NOT NULL DEFAULT 0,
              last_used_at timestamptz,
              expires_at timestamptz NOT NULL,
              revoked_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_user_quick_unlocks_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_user_quick_unlocks_device_token_hash UNIQUE (tenant_id, device_token_hash),
              CONSTRAINT fk_user_quick_unlocks_user_id FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE user_quick_unlocks IS 'Reingreso rápido en un teléfono (ESS-6): token de dispositivo (solo su SHA-256, en cookie HttpOnly) más PIN con hash; caduca, se olvida tras varios PIN errados y se revoca.';
            CREATE INDEX IF NOT EXISTS ix_user_quick_unlocks_user_id ON user_quick_unlocks (tenant_id, user_id);
            ALTER TABLE user_quick_unlocks ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_quick_unlocks FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'user_quick_unlocks' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON user_quick_unlocks
                  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_user_quick_unlocks_touch') THEN
                CREATE TRIGGER trg_user_quick_unlocks_touch BEFORE UPDATE ON user_quick_unlocks FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON user_quick_unlocks TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON user_quick_unlocks TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('seguridad.pin_dias_vigencia', 'base', 'Vigencia del PIN de reingreso (días)', 'ESS-6 · Días que dura el reingreso rápido con PIN en un teléfono; al vencer se vuelve a ingresar con clave o código. 0: el PIN no se ofrece.', 'integer', '30'::jsonb, ARRAY['organization'], '{"min": 0, "max": 180}'::jsonb),
              ('seguridad.pin_intentos', 'base', 'Intentos de PIN antes de olvidarlo', 'ESS-6 · PIN incorrectos seguidos tras los cuales el teléfono olvida el reingreso rápido.', 'integer', '5'::jsonb, ARRAY['organization'], '{"min": 3, "max": 10}'::jsonb)
            ON CONFLICT (code) DO NOTHING;

            UPDATE parameter_definitions SET default_value = '["in_app", "email", "push"]'::jsonb
              WHERE code = 'notificaciones.canales_activos' AND NOT (default_value ? 'push');
            SQL);
    }

    public function down(): void {}
};
