<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS-1 · POS-2 · Posiciones como plazas: vigencia de la plaza (valid_from / valid_to), banda de
 * referencia, posición compartida con cupos (RN-POS-02, controlada por disparador en las asignaciones
 * en lugar de la exclusión de un solo ocupante) y acción en el historial de estados.
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
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS salary_band_id uuid;
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS is_shared boolean NOT NULL DEFAULT false;
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS seats smallint NOT NULL DEFAULT 1;
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS valid_from date;
            ALTER TABLE positions ADD COLUMN IF NOT EXISTS valid_to date;
            UPDATE positions SET valid_from = COALESCE(budgeted_from, created_at::date) WHERE valid_from IS NULL;
            ALTER TABLE positions ALTER COLUMN valid_from SET DEFAULT current_date;
            ALTER TABLE positions ALTER COLUMN valid_from SET NOT NULL;
            CREATE INDEX IF NOT EXISTS ix_positions_salary_band_id ON positions (tenant_id, salary_band_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_positions_salary_band_id') THEN
                ALTER TABLE positions ADD CONSTRAINT fk_positions_salary_band_id FOREIGN KEY (tenant_id, salary_band_id) REFERENCES salary_bands (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_positions_1') THEN
                ALTER TABLE positions ADD CONSTRAINT ck_positions_1 CHECK (seats >= 1 AND (is_shared OR seats = 1));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_positions_2') THEN
                ALTER TABLE positions ADD CONSTRAINT ck_positions_2 CHECK (valid_to IS NULL OR valid_to >= valid_from);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_positions_3') THEN
                ALTER TABLE positions ADD CONSTRAINT ck_positions_3 CHECK (reports_to_position_id IS NULL OR reports_to_position_id <> id);
              END IF;
            END $$;
            COMMENT ON TABLE positions IS 'Posición o plaza presupuestada (C-02): una silla concreta con puesto de catálogo, unidad, sede, centro de costo, posición jefe y banda de referencia (POS-1). Admite un ocupante, o tantos como seats si es compartida (RN-POS-02). Ocupada o vacante se deriva de las asignaciones vigentes (POS-2). valid_from / valid_to: vigencia de la plaza.';

            ALTER TABLE position_status_events ADD COLUMN IF NOT EXISTS action text;
            COMMENT ON TABLE position_status_events IS 'Historial inalterable de estados de una posición (aprobada, congelada, cerrada...) con la acción, el motivo y quién la hizo (POS-2).';

            ALTER TABLE employment_assignments DROP CONSTRAINT IF EXISTS ex_employment_assignments_r1;
            CREATE OR REPLACE FUNCTION check_position_seats() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
              cupos integer;
              usados integer;
            BEGIN
              IF NEW.position_id IS NULL THEN
                RETURN NEW;
              END IF;
              SELECT seats INTO cupos FROM positions WHERE tenant_id = NEW.tenant_id AND id = NEW.position_id;
              SELECT count(*) INTO usados FROM employment_assignments a
               WHERE a.tenant_id = NEW.tenant_id AND a.position_id = NEW.position_id AND a.id <> NEW.id
                 AND daterange(a.valid_from, a.valid_to, '[]') && daterange(NEW.valid_from, NEW.valid_to, '[]');
              IF usados >= COALESCE(cupos, 1) THEN
                RAISE EXCEPTION 'La posición % ya tiene % ocupante(s) en esas fechas y solo admite %.', NEW.position_id, usados, COALESCE(cupos, 1) USING ERRCODE = 'AY007';
              END IF;
              RETURN NEW;
            END
            $fn$;
            DROP TRIGGER IF EXISTS trg_employment_assignments_position_seats ON employment_assignments;
            CREATE TRIGGER trg_employment_assignments_position_seats BEFORE INSERT OR UPDATE OF position_id, valid_from, valid_to ON employment_assignments
              FOR EACH ROW EXECUTE FUNCTION check_position_seats();
            COMMENT ON TABLE employment_assignments IS 'Asignación con vigencia (C-03): puesto, posición, unidad, sede, jefe y centro de costo. Fuente única de «dónde trabaja y a quién reporta» (C-11). Los cupos de la posición (seats) los controla un disparador (RN-POS-02).';
        SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: las columnas guardan la vigencia y los cupos de plazas reales.
    }
};
