<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG-5 · Catálogo de puestos: familias, niveles y categorías laborales se archivan (estado y
 * versión de bloqueo), y la descripción de cada puesto se versiona en job_profile_versions
 * (solo inserción). kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una
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
            ALTER TABLE job_families ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active';
            ALTER TABLE job_families ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 1;
            ALTER TABLE job_levels ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active';
            ALTER TABLE job_levels ADD COLUMN IF NOT EXISTS lock_version integer NOT NULL DEFAULT 1;
            ALTER TABLE job_categories ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active';
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'job_families_status_check') THEN
                ALTER TABLE job_families ADD CONSTRAINT job_families_status_check CHECK (status IN ('active','inactive'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'job_levels_status_check') THEN
                ALTER TABLE job_levels ADD CONSTRAINT job_levels_status_check CHECK (status IN ('active','inactive'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'job_categories_status_check') THEN
                ALTER TABLE job_categories ADD CONSTRAINT job_categories_status_check CHECK (status IN ('active','inactive'));
              END IF;
            END $$;
            DROP TRIGGER IF EXISTS trg_job_families_touch ON job_families;
            CREATE TRIGGER trg_job_families_touch BEFORE UPDATE ON job_families FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
            DROP TRIGGER IF EXISTS trg_job_levels_touch ON job_levels;
            CREATE TRIGGER trg_job_levels_touch BEFORE UPDATE ON job_levels FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
            COMMENT ON TABLE job_families IS 'Familias de puestos (catálogo configurable; se archivan, no se borran).';
            COMMENT ON TABLE job_levels IS 'Niveles jerárquicos del catálogo de puestos (catálogo configurable; se archivan, no se borran).';

            CREATE TABLE IF NOT EXISTS job_profile_versions (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              job_profile_id uuid NOT NULL,
              version integer NOT NULL,
              name text NOT NULL,
              mission text,
              functions text,
              requirements text,
              change_reason text,
              changed_by_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_job_profile_versions_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_job_profile_versions_job_profile_id_version UNIQUE (tenant_id, job_profile_id, version),
              CONSTRAINT fk_job_profile_versions_job_profile_id FOREIGN KEY (tenant_id, job_profile_id) REFERENCES job_profiles (tenant_id, id),
              CONSTRAINT fk_job_profile_versions_changed_by_user_id FOREIGN KEY (tenant_id, changed_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE job_profile_versions IS 'Versiones inalterables de la descripción de un puesto de catálogo (misión, funciones y requisitos), ORG-5.';
            CREATE INDEX IF NOT EXISTS ix_job_profile_versions_changed_by_user_id ON job_profile_versions (tenant_id, changed_by_user_id);
            ALTER TABLE job_profile_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE job_profile_versions FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policy WHERE polname = 'tenant_isolation' AND polrelid = 'job_profile_versions'::regclass) THEN
                CREATE POLICY tenant_isolation ON job_profile_versions USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_job_profile_versions_inmutable') THEN
                CREATE TRIGGER trg_job_profile_versions_inmutable BEFORE UPDATE OR DELETE ON job_profile_versions FOR EACH ROW EXECUTE FUNCTION reject_mutation();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_job_profile_versions_no_truncate') THEN
                CREATE TRIGGER trg_job_profile_versions_no_truncate BEFORE TRUNCATE ON job_profile_versions FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT ON job_profile_versions TO gestia_app;
                REVOKE UPDATE, DELETE ON job_profile_versions FROM gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                REVOKE UPDATE, DELETE ON job_profile_versions FROM gestia_platform;
              END IF;
            END $$;

            -- Puestos que ya existían: su descripción actual es la versión 1 (recorre todas las
            -- organizaciones, así que solo corre con un rol que no está sujeto a RLS).
            DO $$ BEGIN
              IF (SELECT rolsuper OR rolbypassrls FROM pg_roles WHERE rolname = current_user) THEN
                INSERT INTO job_profile_versions (tenant_id, job_profile_id, version, name, mission, functions, requirements, change_reason)
                SELECT p.tenant_id, p.id, 1, p.name, p.mission, p.functions, p.requirements, NULL
                FROM job_profiles p
                WHERE NOT EXISTS (SELECT 1 FROM job_profile_versions v WHERE v.job_profile_id = p.id);
              END IF;
            END $$;
            SQL);
    }
};
