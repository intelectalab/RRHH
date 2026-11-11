<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEG-4 · SEG-5 · Historial de claves y parámetros de política de claves, bloqueo y sesiones.
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
            CREATE TABLE IF NOT EXISTS password_history (
              id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              user_id uuid NOT NULL,
              password_hash text NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_password_history_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_password_history_user_id FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id)
            );
            CREATE INDEX IF NOT EXISTS ix_password_history_user_id ON password_history (tenant_id, user_id, created_at DESC);
            ALTER TABLE password_history ENABLE ROW LEVEL SECURITY;
            ALTER TABLE password_history FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'password_history' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON password_history USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
            END $$;
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ayni_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON password_history TO ayni_app;
              END IF;
            END $$;
            SQL);

        DB::unprepared(<<<'SQL'
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('seguridad.clave_longitud_minima', 'base', 'Longitud mínima de la clave', 'Cantidad mínima de caracteres de una clave nueva.', 'integer', '12'::jsonb, ARRAY['organization'], '{"min": 10, "max": 64}'::jsonb),
              ('seguridad.clave_historial', 'base', 'Claves anteriores que no se pueden repetir', 'Cuántas claves anteriores se recuerdan para impedir reutilizarlas. 0: no se controla.', 'integer', '5'::jsonb, ARRAY['organization'], '{"min": 0, "max": 24}'::jsonb),
              ('seguridad.clave_vencimiento_dias', 'base', 'Vencimiento de la clave (días)', 'Días tras los cuales se pide cambiar la clave. 0: no vence.', 'integer', '0'::jsonb, ARRAY['organization'], '{"min": 0, "max": 365}'::jsonb),
              ('seguridad.intentos_maximos', 'base', 'Intentos fallidos antes del bloqueo', 'Ingresos fallidos seguidos que bloquean la cuenta temporalmente.', 'integer', '5'::jsonb, ARRAY['organization'], '{"min": 3, "max": 10}'::jsonb),
              ('seguridad.bloqueo_minutos', 'base', 'Duración del bloqueo (minutos)', 'Tiempo que la cuenta queda bloqueada tras superar los intentos.', 'integer', '15'::jsonb, ARRAY['organization'], '{"min": 5, "max": 1440}'::jsonb),
              ('seguridad.sesion_duracion_minutos', 'base', 'Duración máxima de la sesión (minutos)', 'Tiempo tras el cual hay que volver a ingresar aunque se esté usando.', 'integer', '480'::jsonb, ARRAY['organization'], '{"min": 30, "max": 1440}'::jsonb),
              ('seguridad.inactividad_minutos', 'base', 'Cierre por inactividad (minutos)', 'Minutos sin uso tras los cuales la sesión se cierra.', 'integer', '30'::jsonb, ARRAY['organization'], '{"min": 5, "max": 240}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
