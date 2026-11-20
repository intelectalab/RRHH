<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AUS-5 · AUS-6 · Cierre del resumen de ausencias de un periodo (solo inserción: un cambio posterior se informa
 * como diferencia) y parámetros de jornada (inicio y fin) que definen la desconexión digital del teletrabajador.
 *
 * database/schema.sql lo trae para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS absence_period_closings (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              employment_id uuid NOT NULL,
              period_from date NOT NULL,
              period_to date NOT NULL,
              fingerprint text NOT NULL,
              summary jsonb NOT NULL,
              closed_by_user_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_absence_period_closings_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_absence_period_closings_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_absence_period_closings_closed_by_user_id FOREIGN KEY (tenant_id, closed_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE absence_period_closings IS 'AUS-6 · Resumen de ausencias de un trabajador para un periodo, congelado al cerrar el periodo (solo inserción). Un cambio posterior se informa como diferencia contra el último cierre; cerrar otra vez con cambios agrega otra fila.';
            CREATE INDEX IF NOT EXISTS ix_absence_period_closings_closed_by_user_id ON absence_period_closings (tenant_id, closed_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_absence_period_closings_employment_id_period_from_period_to ON absence_period_closings (tenant_id, employment_id, period_from, period_to);
            ALTER TABLE absence_period_closings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE absence_period_closings FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'absence_period_closings' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON absence_period_closings
                  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_absence_period_closings_inmutable') THEN
                CREATE TRIGGER trg_absence_period_closings_inmutable BEFORE UPDATE OR DELETE ON absence_period_closings FOR EACH ROW EXECUTE FUNCTION reject_mutation();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_absence_period_closings_no_truncate') THEN
                CREATE TRIGGER trg_absence_period_closings_no_truncate BEFORE TRUNCATE ON absence_period_closings FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT ON absence_period_closings TO gestia_app;
                REVOKE UPDATE, DELETE ON absence_period_closings FROM gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT ON absence_period_closings TO gestia_platform;
                REVOKE UPDATE, DELETE ON absence_period_closings FROM gestia_platform;
              END IF;
            END $$;
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('jornada.hora_inicio', 'core', 'Inicio de la jornada', 'AUS-5 · Hora local de inicio de la jornada. Con el fin de la jornada define la desconexión digital del teletrabajador (los avisos no urgentes esperan al inicio de la siguiente jornada) hasta que el módulo de turnos asigne un horario por trabajador. Vacío: no se difieren avisos.', 'time', 'null'::jsonb, ARRAY['organization','legal_entity','site'], NULL),
              ('jornada.hora_fin', 'core', 'Fin de la jornada', 'AUS-5 · Hora local de fin de la jornada. Desde aquí corre la desconexión mínima (la mayor entre la del acuerdo de teletrabajo y el parámetro legal teletrabajo_desconexion_horas).', 'time', 'null'::jsonb, ARRAY['organization','legal_entity','site'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        // Ayuda de la pantalla Resumen del periodo (solo crea lo que falta).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
