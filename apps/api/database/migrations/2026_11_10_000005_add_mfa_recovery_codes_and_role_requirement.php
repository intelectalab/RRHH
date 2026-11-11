<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEG-3 · Segundo factor: códigos de respaldo, MFA obligatorio por rol (RN-SEG-08, ADR 0009)
 * y parámetro para exigirlo a toda la organización.
 * database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE system_roles ADD COLUMN IF NOT EXISTS mfa_required_default boolean NOT NULL DEFAULT false;
            UPDATE system_roles SET mfa_required_default = true WHERE code IN ('superadmin_saas', 'admin_empresa', 'payroll', 'investigador_denuncias');
            ALTER TABLE roles ADD COLUMN IF NOT EXISTS mfa_required boolean;

            CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes (
              id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              user_id uuid NOT NULL,
              code_hash text NOT NULL,
              used_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_user_mfa_recovery_codes_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_user_mfa_recovery_codes_user_id FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id)
            );
            CREATE INDEX IF NOT EXISTS ix_user_mfa_recovery_codes_user_id ON user_mfa_recovery_codes (tenant_id, user_id);
            ALTER TABLE user_mfa_recovery_codes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE user_mfa_recovery_codes FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'user_mfa_recovery_codes' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON user_mfa_recovery_codes USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
            END $$;
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON user_mfa_recovery_codes TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON user_mfa_recovery_codes TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('seguridad.mfa_todos', 'base', 'Verificación en dos pasos para todos', 'Exige el segundo factor a todos los usuarios, además de los roles que ya lo exigen.', 'boolean', 'false'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
