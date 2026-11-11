<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SAS-1 · Consola separada del superadministrador: sesiones propias de la consola y
 * segundo factor (siempre obligatorio, ADR 0011) con bloqueo por intentos fallidos.
 * database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes.
 * Son tablas de plataforma (sin tenant_id ni RLS): solo las usa la consola.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE platform_users ADD COLUMN IF NOT EXISTS mfa_confirmed_at timestamptz;
            ALTER TABLE platform_users ADD COLUMN IF NOT EXISTS mfa_last_used_step bigint;
            ALTER TABLE platform_users ADD COLUMN IF NOT EXISTS failed_attempts integer NOT NULL DEFAULT 0;
            ALTER TABLE platform_users ADD COLUMN IF NOT EXISTS locked_until timestamptz;

            CREATE TABLE IF NOT EXISTS platform_sessions (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              platform_user_id uuid NOT NULL,
              session_hash text NOT NULL,
              ip inet,
              user_agent text,
              mfa_passed boolean NOT NULL DEFAULT false,
              last_seen_at timestamptz,
              expires_at timestamptz NOT NULL,
              revoked_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_platform_sessions_session_hash UNIQUE (session_hash),
              CONSTRAINT fk_platform_sessions_platform_user_id FOREIGN KEY (platform_user_id) REFERENCES platform_users (id)
            );
            COMMENT ON TABLE platform_sessions IS 'Sesiones de la consola SaaS (SAS-1): cookie propia, segundo factor siempre obligatorio, revocables.';
            CREATE INDEX IF NOT EXISTS ix_platform_sessions_platform_user_id ON platform_sessions (platform_user_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_platform_sessions_touch') THEN
                CREATE TRIGGER trg_platform_sessions_touch BEFORE UPDATE ON platform_sessions FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON platform_sessions TO gestia_platform;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void {}
};
