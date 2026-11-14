<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VAC-3 · VAC-4 · Solicitud de vacaciones con su flujo: días calendario, adelanto, envío, decisión,
 * cancelación, inicio y fin del goce, y la exclusión que impide dos solicitudes activas solapadas del
 * mismo trabajador. Agrega el parámetro vacaciones.maximo_ausentes_simultaneos (alerta de cobertura del
 * calendario del equipo). kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una
 * base existente (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS btree_gist;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS calendar_days numeric(6,2);
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS advance_days numeric(6,2);
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS exception_reason text;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS decided_at timestamptz;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS decision_reason text;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS cancelled_at timestamptz;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS cancelled_by_user_id uuid;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS cancellation_reason text;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS started_at timestamptz;
            ALTER TABLE vacation_requests ADD COLUMN IF NOT EXISTS completed_at timestamptz;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_vacation_requests_cancelled_by_user_id') THEN
                ALTER TABLE vacation_requests ADD CONSTRAINT fk_vacation_requests_cancelled_by_user_id FOREIGN KEY (tenant_id, cancelled_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ex_vacation_requests_r1') THEN
                ALTER TABLE vacation_requests ADD CONSTRAINT ex_vacation_requests_r1 EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&) WHERE (deleted_at IS NULL AND kind IN ('leave','advance') AND status IN ('pending','hr_review','approved','in_progress','taken')) DEFERRABLE INITIALLY IMMEDIATE;
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_vacation_requests_cancelled_by_user_id ON vacation_requests (tenant_id, cancelled_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_requests_status_starts_on ON vacation_requests (tenant_id, status, starts_on);
            COMMENT ON TABLE vacation_requests IS 'Solicitud de vacaciones (goce, adelanto o venta) con su flujo de aprobación. VAC-3: days es lo que descuenta del saldo según el cómputo de la política (calendario o hábil, con los feriados de la sede); advance_days es la parte tomada a cuenta del periodo en curso. La exclusión impide dos solicitudes activas solapadas del mismo trabajador.';

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('vacaciones.maximo_ausentes_simultaneos', 'time_off', 'Máximo de ausentes simultáneos por unidad', 'VAC-4 · Si más personas de una misma unidad estarán ausentes el mismo día (vacaciones y ausencias aprobadas o pendientes), el calendario del equipo y la aprobación lo alertan. 0 = sin límite.', 'integer', '0'::jsonb, ARRAY['organization','legal_entity'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
