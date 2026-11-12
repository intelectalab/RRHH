<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG-3 · Árbol de unidades organizacionales.
 *  - org_unit_types: niveles con los nombres de cada organización (reemplazan el enum fijo kind).
 *  - org_unit_versions: estructura con vigencia (superior, nombre, tipo, responsable, centro de costo).
 *  - org_units.status pasa a active / archived (las acciones archive y restore).
 * kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente
 * (idempotente). Las unidades que ya existieran reciben su tipo y una primera versión.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS org_unit_types (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              code text NOT NULL,
              name text NOT NULL,
              rank smallint NOT NULL DEFAULT 0,
              status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','archived')),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_org_unit_types_tenant_id UNIQUE (tenant_id, id)
            );
            COMMENT ON TABLE org_unit_types IS 'Tipos o niveles de unidad con los nombres que usa cada organización (gerencia, área, departamento, equipo…); rank ordena del nivel más alto al más bajo (ORG-3).';
            CREATE UNIQUE INDEX IF NOT EXISTS uq_org_unit_types_code ON org_unit_types (tenant_id, code) WHERE deleted_at IS NULL;
            ALTER TABLE org_unit_types ENABLE ROW LEVEL SECURITY;
            ALTER TABLE org_unit_types FORCE ROW LEVEL SECURITY;

            ALTER TABLE org_units ADD COLUMN IF NOT EXISTS org_unit_type_id uuid;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_org_units_org_unit_type_id') THEN
                ALTER TABLE org_units ADD CONSTRAINT fk_org_units_org_unit_type_id FOREIGN KEY (tenant_id, org_unit_type_id) REFERENCES org_unit_types (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_org_units_org_unit_type_id ON org_units (tenant_id, org_unit_type_id);

            CREATE TABLE IF NOT EXISTS org_unit_versions (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              org_unit_id uuid NOT NULL,
              parent_id uuid,
              name text NOT NULL,
              org_unit_type_id uuid,
              head_position_id uuid,
              cost_center_id uuid,
              reason text,
              changed_by_user_id uuid,
              valid_from date NOT NULL,
              valid_to date,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_org_unit_versions_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_org_unit_versions_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ex_org_unit_versions_vigencia EXCLUDE USING gist (tenant_id WITH =, org_unit_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT fk_org_unit_versions_org_unit_id FOREIGN KEY (tenant_id, org_unit_id) REFERENCES org_units (tenant_id, id),
              CONSTRAINT fk_org_unit_versions_parent_id FOREIGN KEY (tenant_id, parent_id) REFERENCES org_units (tenant_id, id),
              CONSTRAINT fk_org_unit_versions_org_unit_type_id FOREIGN KEY (tenant_id, org_unit_type_id) REFERENCES org_unit_types (tenant_id, id),
              CONSTRAINT fk_org_unit_versions_changed_by_user_id FOREIGN KEY (tenant_id, changed_by_user_id) REFERENCES users (tenant_id, id),
              CONSTRAINT fk_org_unit_versions_head_position_id FOREIGN KEY (tenant_id, head_position_id) REFERENCES positions (tenant_id, id),
              CONSTRAINT fk_org_unit_versions_cost_center_id FOREIGN KEY (tenant_id, cost_center_id) REFERENCES cost_centers (tenant_id, id)
            );
            COMMENT ON TABLE org_unit_versions IS 'Estructura de una unidad con vigencia (ORG-3): superior, nombre, tipo, responsable y centro de costo desde una fecha. Mover o renombrar cierra la versión vigente e inserta otra; los reportes de periodos anteriores leen la versión de su fecha.';
            CREATE INDEX IF NOT EXISTS ix_org_unit_versions_parent_id ON org_unit_versions (tenant_id, parent_id);
            CREATE INDEX IF NOT EXISTS ix_org_unit_versions_org_unit_type_id ON org_unit_versions (tenant_id, org_unit_type_id);
            CREATE INDEX IF NOT EXISTS ix_org_unit_versions_head_position_id ON org_unit_versions (tenant_id, head_position_id);
            CREATE INDEX IF NOT EXISTS ix_org_unit_versions_cost_center_id ON org_unit_versions (tenant_id, cost_center_id);
            CREATE INDEX IF NOT EXISTS ix_org_unit_versions_changed_by_user_id ON org_unit_versions (tenant_id, changed_by_user_id);
            ALTER TABLE org_unit_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE org_unit_versions FORCE ROW LEVEL SECURITY;

            DO $$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['org_unit_types', 'org_unit_versions'] LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_policy WHERE polname = 'tenant_isolation' AND polrelid = t::regclass) THEN
                  EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid)', t);
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_' || t || '_touch') THEN
                  EXECUTE format('CREATE TRIGGER %I BEFORE UPDATE ON %I FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock()', 'trg_' || t || '_touch', t);
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                  EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO gestia_app', t);
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                  EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON %I TO gestia_platform', t);
                END IF;
              END LOOP;
            END $$;
            SQL);

        // Unidades previas: cada valor del enum kind pasa a ser un tipo de la organización y cada
        // unidad recibe su primera versión. Se hace como superusuario sin RLS solo si hay filas.
        if (DB::table('information_schema.columns')->where('table_name', 'org_units')->where('column_name', 'kind')->exists()) {
            DB::unprepared(<<<'SQL'
                INSERT INTO org_unit_types (tenant_id, code, name, rank)
                SELECT DISTINCT u.tenant_id, u.kind, initcap(u.kind),
                       array_position(ARRAY['management','area','department','subdepartment','unit','team'], u.kind)::smallint
                  FROM org_units u
                 WHERE u.kind IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM org_unit_types t WHERE t.tenant_id = u.tenant_id AND t.code = u.kind AND t.deleted_at IS NULL);
                UPDATE org_units u SET org_unit_type_id = t.id
                  FROM org_unit_types t
                 WHERE t.tenant_id = u.tenant_id AND t.code = u.kind AND u.org_unit_type_id IS NULL;
                ALTER TABLE org_units DROP COLUMN kind;
                SQL);
        }
        DB::unprepared(<<<'SQL'
            UPDATE org_units SET status = 'archived' WHERE status = 'inactive';
            ALTER TABLE org_units DROP CONSTRAINT IF EXISTS org_units_status_check;
            ALTER TABLE org_units ADD CONSTRAINT org_units_status_check CHECK (status IN ('active','archived'));
            INSERT INTO org_unit_versions (tenant_id, org_unit_id, parent_id, name, org_unit_type_id, head_position_id, cost_center_id, valid_from)
            SELECT u.tenant_id, u.id, u.parent_id, u.name, u.org_unit_type_id, u.head_position_id, u.cost_center_id,
                   COALESCE(u.valid_from, u.created_at::date)
              FROM org_units u
             WHERE NOT EXISTS (SELECT 1 FROM org_unit_versions v WHERE v.org_unit_id = u.id);
            COMMENT ON TABLE org_units IS 'Unidad organizativa jerárquica. Las columnas de estructura (superior, nombre, tipo, responsable, centro de costo, ruta) reflejan la versión vigente hoy; la historia y los cambios programados viven en org_unit_versions (ORG-3). valid_from / valid_to marcan la vida de la unidad.';
            SQL);
    }

    public function down(): void {}
};
