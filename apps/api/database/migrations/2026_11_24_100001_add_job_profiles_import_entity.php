<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · El catálogo de puestos se importa como tipo de datos propio (job_profiles).
 * IMP-6 · Regla 2: deshacer una importación no borra historia laboral. Asignación, sueldo y régimen
 * ganan deleted_at (papelera); la exclusión de solapes y el control de cupos ignoran lo borrado, el rol
 * de aplicación deja de ver esas filas (as_of y todas las consultas) y solo trash_labor_history_row las
 * marca. Idempotente: se puede correr sobre el esquema anterior y sobre el nuevo.
 */
return new class extends Migration
{
    private const ENTITIES = "'legal_entities','employees','contracts','vacation_balances','salaries','attendance','concepts','history','org_structure','dependents','positions','job_profiles'";

    private const HISTORY = ['employment_assignments', 'employment_compensations', 'employment_labor_regimes'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['import_jobs', 'import_templates'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_entity_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_entity_check CHECK (entity IN (".self::ENTITIES.'))');
        }

        foreach (self::HISTORY as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS deleted_at timestamptz");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS ex_{$table}_vigencia");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT ex_{$table}_vigencia EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) WHERE (deleted_at IS NULL) DEFERRABLE INITIALLY IMMEDIATE");
            DB::statement("DROP POLICY IF EXISTS hide_deleted ON {$table}");
            DB::statement("CREATE POLICY hide_deleted ON {$table} AS RESTRICTIVE FOR SELECT TO gestia_app USING (deleted_at IS NULL)");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION check_position_seats() RETURNS trigger
LANGUAGE plpgsql
AS $$
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
     AND a.deleted_at IS NULL
     AND daterange(a.valid_from, a.valid_to, '[]') && daterange(NEW.valid_from, NEW.valid_to, '[]');
  IF usados >= COALESCE(cupos, 1) THEN
    RAISE EXCEPTION 'La posición % ya tiene % ocupante(s) en esas fechas y solo admite %.', NEW.position_id, usados, COALESCE(cupos, 1) USING ERRCODE = 'AY007';
  END IF;
  RETURN NEW;
END
$$;
CREATE OR REPLACE FUNCTION trash_labor_history_row(p_table text, p_id uuid) RETURNS boolean
  LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
  IF p_table NOT IN ('employment_assignments', 'employment_compensations', 'employment_labor_regimes') THEN
    RAISE EXCEPTION 'tabla no admitida: %', p_table;
  END IF;
  EXECUTE format('UPDATE %I SET deleted_at = now() WHERE id = $1 AND tenant_id = app_tenant_id() AND deleted_at IS NULL', p_table) USING p_id;
  GET DIAGNOSTICS n = ROW_COUNT;
  RETURN n = 1;
END
$$;
REVOKE ALL ON FUNCTION trash_labor_history_row(text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION trash_labor_history_row(text, uuid) TO gestia_app;
SQL);
    }

    public function down(): void
    {
        // La papelera de la historia laboral no se quita: hacerlo expondría filas borradas.
    }
};
