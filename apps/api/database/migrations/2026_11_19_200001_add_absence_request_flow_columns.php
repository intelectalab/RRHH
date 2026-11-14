<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUS-2 · Solicitud y registro de ausencias: fechas laborales y horas, cómputo en días hábiles por
 * tipo (absence_type_rules.day_count), registro directo de RRHH, plazo del sustento, decisión y
 * cancelación, y la exclusión que impide ausencias solapadas del mismo trabajador. Agrega el
 * parámetro ausencias.dias_descanso_semanal. kit/database/schema.sql ya trae este diseño en bases
 * nuevas; aquí se adapta una base existente (idempotente).
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
            ALTER TABLE absence_type_rules ADD COLUMN IF NOT EXISTS day_count text NOT NULL DEFAULT 'calendar';
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS starts_on date;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS ends_on date;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS start_time time;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS end_time time;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS calendar_days numeric(6,2);
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS registered_directly boolean NOT NULL DEFAULT false;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS document_due_on date;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS exception_reason text;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS decided_at timestamptz;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS decision_reason text;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS cancelled_at timestamptz;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS cancelled_by_user_id uuid;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS cancellation_reason text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'absence_type_rules_day_count_check') THEN
                ALTER TABLE absence_type_rules ADD CONSTRAINT absence_type_rules_day_count_check CHECK (day_count IN ('calendar','working'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_absence_requests_2') THEN
                ALTER TABLE absence_requests ADD CONSTRAINT ck_absence_requests_2 CHECK (starts_on IS NULL OR ends_on >= starts_on);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_absence_requests_cancelled_by_user_id') THEN
                ALTER TABLE absence_requests ADD CONSTRAINT fk_absence_requests_cancelled_by_user_id FOREIGN KEY (tenant_id, cancelled_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ex_absence_requests_r1') THEN
                ALTER TABLE absence_requests ADD CONSTRAINT ex_absence_requests_r1 EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (deleted_at IS NULL AND status IN ('pending','pending_document','approved','in_progress','completed')) DEFERRABLE INITIALLY IMMEDIATE;
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_absence_requests_cancelled_by_user_id ON absence_requests (tenant_id, cancelled_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_absence_requests_status_starts_on ON absence_requests (tenant_id, status, starts_on);
            COMMENT ON TABLE absence_requests IS 'Ausencia o permiso solicitado, con días a cargo del empleador y días subsidiados. AUS-2: las fechas laborales (starts_on, ends_on) son la referencia; starts_at y ends_at son el instante en la zona horaria de la empresa (fin exclusivo) y alimentan la exclusión que impide ausencias solapadas del mismo trabajador.';

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('ausencias.dias_descanso_semanal', 'time_off', 'Días de descanso semanal (cómputo en días hábiles)', 'AUS-2 · Días de la semana que no cuentan cuando un tipo de ausencia se computa en días hábiles (además de los feriados del calendario de la sede). Se reemplaza por el horario del trabajador cuando exista el módulo de Tiempo.', 'list', '["saturday", "sunday"]'::jsonb, ARRAY['organization','legal_entity','site'], '["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
