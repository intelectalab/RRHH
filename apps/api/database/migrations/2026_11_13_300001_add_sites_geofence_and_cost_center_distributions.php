<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG-2 · ORG-4 · Sedes con radio de geocerca y calendario de feriados; centros de costo con vigencia
 * (opened_on / closed_on) y estado «cerrado»; distribución con vigencia de una relación laboral entre
 * centros de costo, cuyas líneas suman 100 % (disparador diferido, RN-ORG-06).
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
            ALTER TABLE sites ADD COLUMN IF NOT EXISTS geofence_radius_m integer;
            ALTER TABLE sites ADD COLUMN IF NOT EXISTS holiday_calendar_id uuid;
            CREATE INDEX IF NOT EXISTS ix_sites_holiday_calendar_id ON sites (tenant_id, holiday_calendar_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_sites_holiday_calendar_id') THEN
                ALTER TABLE sites ADD CONSTRAINT fk_sites_holiday_calendar_id FOREIGN KEY (tenant_id, holiday_calendar_id) REFERENCES holiday_calendars (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sites_2') THEN
                ALTER TABLE sites ADD CONSTRAINT ck_sites_2 CHECK ((latitude IS NULL) = (longitude IS NULL) AND (latitude IS NULL OR latitude BETWEEN -90 AND 90) AND (longitude IS NULL OR longitude BETWEEN -180 AND 180));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_sites_3') THEN
                ALTER TABLE sites ADD CONSTRAINT ck_sites_3 CHECK (geofence_radius_m IS NULL OR (geofence_radius_m > 0 AND latitude IS NOT NULL));
              END IF;
            END $$;

            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS opened_on date;
            ALTER TABLE cost_centers ADD COLUMN IF NOT EXISTS closed_on date;
            ALTER TABLE cost_centers DROP CONSTRAINT IF EXISTS cost_centers_status_check;
            ALTER TABLE cost_centers ADD CONSTRAINT cost_centers_status_check CHECK (status IN ('active','closed','inactive'));
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_cost_centers_1') THEN
                ALTER TABLE cost_centers ADD CONSTRAINT ck_cost_centers_1 CHECK (closed_on IS NULL OR opened_on IS NULL OR closed_on >= opened_on);
              END IF;
            END $$;
            COMMENT ON TABLE cost_centers IS 'Centro de costo para imputar planilla, gastos y horas. opened_on / closed_on delimitan su vigencia (closed_on = último día que admite imputaciones); cerrado no admite nuevas asignaciones.';

            CREATE TABLE IF NOT EXISTS employment_cost_distributions (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              employment_id uuid NOT NULL,
              reason text,
              valid_from date NOT NULL,
              valid_to date,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_employment_cost_distributions_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_employment_cost_distributions_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ex_employment_cost_distributions_vigencia EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT fk_employment_cost_distributions_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id)
            );
            COMMENT ON TABLE employment_cost_distributions IS 'Distribución con vigencia de una relación laboral entre varios centros de costo (ORG-4 · RN-ORG-06); sus líneas suman siempre 100 %.';

            CREATE TABLE IF NOT EXISTS employment_cost_distribution_lines (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              employment_cost_distribution_id uuid NOT NULL,
              cost_center_id uuid NOT NULL,
              percentage numeric(5,2) NOT NULL,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_employment_cost_distribution_lines_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_employment_cost_distribution_lines_employment_cost__a151fa87 UNIQUE (tenant_id, employment_cost_distribution_id, cost_center_id),
              CONSTRAINT ck_employment_cost_distribution_lines_1 CHECK (percentage > 0 AND percentage <= 100),
              CONSTRAINT fk_employment_cost_distribution_lines_employment_cost__cd0fb5f0 FOREIGN KEY (tenant_id, employment_cost_distribution_id) REFERENCES employment_cost_distributions (tenant_id, id),
              CONSTRAINT fk_employment_cost_distribution_lines_cost_center_id FOREIGN KEY (tenant_id, cost_center_id) REFERENCES cost_centers (tenant_id, id)
            );
            COMMENT ON TABLE employment_cost_distribution_lines IS 'Línea de una distribución: centro de costo y porcentaje (dos decimales).';
            CREATE INDEX IF NOT EXISTS ix_employment_cost_distribution_lines_cost_center_id ON employment_cost_distribution_lines (tenant_id, cost_center_id);

            ALTER TABLE employment_cost_distributions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE employment_cost_distributions FORCE ROW LEVEL SECURITY;
            ALTER TABLE employment_cost_distribution_lines ENABLE ROW LEVEL SECURITY;
            ALTER TABLE employment_cost_distribution_lines FORCE ROW LEVEL SECURITY;

            CREATE OR REPLACE FUNCTION check_cost_distribution_total() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            DECLARE
              dist uuid := COALESCE(NEW.employment_cost_distribution_id, OLD.employment_cost_distribution_id);
              total numeric;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM employment_cost_distributions WHERE id = dist) THEN
                RETURN NULL;
              END IF;
              SELECT COALESCE(sum(percentage), 0) INTO total FROM employment_cost_distribution_lines WHERE employment_cost_distribution_id = dist;
              IF total <> 100 THEN
                RAISE EXCEPTION 'La distribución por centros de costo % suma % %%; debe sumar 100 %%.', dist, total USING ERRCODE = 'AY006';
              END IF;
              RETURN NULL;
            END
            $fn$;

            CREATE OR REPLACE FUNCTION check_cost_distribution_has_lines() RETURNS trigger
            LANGUAGE plpgsql
            AS $fn$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM employment_cost_distributions WHERE id = NEW.id) THEN
                RETURN NULL;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM employment_cost_distribution_lines WHERE employment_cost_distribution_id = NEW.id) THEN
                RAISE EXCEPTION 'La distribución por centros de costo % no tiene líneas; debe sumar 100 %%.', NEW.id USING ERRCODE = 'AY006';
              END IF;
              RETURN NULL;
            END
            $fn$;

            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policy WHERE polname = 'tenant_isolation' AND polrelid = 'employment_cost_distributions'::regclass) THEN
                CREATE POLICY tenant_isolation ON employment_cost_distributions USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policy WHERE polname = 'tenant_isolation' AND polrelid = 'employment_cost_distribution_lines'::regclass) THEN
                CREATE POLICY tenant_isolation ON employment_cost_distribution_lines USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_employment_cost_distributions_touch') THEN
                CREATE TRIGGER trg_employment_cost_distributions_touch BEFORE UPDATE ON employment_cost_distributions FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_employment_cost_distribution_lines_touch') THEN
                CREATE TRIGGER trg_employment_cost_distribution_lines_touch BEFORE UPDATE ON employment_cost_distribution_lines FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_cost_distribution_lines_total') THEN
                CREATE CONSTRAINT TRIGGER trg_cost_distribution_lines_total AFTER INSERT OR UPDATE OR DELETE ON employment_cost_distribution_lines
                  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_cost_distribution_total();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_cost_distributions_lines') THEN
                CREATE CONSTRAINT TRIGGER trg_cost_distributions_lines AFTER INSERT ON employment_cost_distributions
                  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_cost_distribution_has_lines();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON employment_cost_distributions, employment_cost_distribution_lines TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON employment_cost_distributions, employment_cost_distribution_lines TO gestia_platform;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void {}
};
