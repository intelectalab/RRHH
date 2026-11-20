<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AYU-4 a AYU-7 · Artículos de la organización, valoración, derivación de consultas y recorridos.
 *
 * - tickets: canal «help_center» y enlace a la consulta derivada desde la ayuda (help_escalation_id).
 * - Parámetro ayuda.revision_anticipacion_dias (RN-AYU-04).
 * - Funciones para que el administrador abra una incidencia al soporte de GestiaHR (support_tickets es de la
 *   plataforma; el rol de aplicación no la toca) y lea su estado, solo para la organización activa.
 * - Carga los recorridos por rol y las novedades de versión incluidos en esta versión (idempotente).
 *
 * database/schema.sql trae todo para instalaciones nuevas; aquí se aplica sobre bases existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_channel_check;
            ALTER TABLE tickets ADD CONSTRAINT tickets_channel_check CHECK (channel IN ('portal','email','whatsapp','ai_assistant','help_center'));
            ALTER TABLE tickets ADD COLUMN IF NOT EXISTS help_escalation_id uuid;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_tickets_help_escalation_id') THEN
                ALTER TABLE tickets ADD CONSTRAINT fk_tickets_help_escalation_id FOREIGN KEY (tenant_id, help_escalation_id) REFERENCES help_escalations (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_tickets_help_escalation_id ON tickets (tenant_id, help_escalation_id);

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('ayuda.revision_anticipacion_dias', 'core', 'Aviso de revisión de artículos (días)', 'AYU-4 · RN-AYU-04: días antes de la fecha de revisión de un artículo de la organización en que se avisa a su responsable y el artículo pasa a «por revisar» (sigue visible).', 'integer', '7'::jsonb, ARRAY['organization'], '{"min": 0, "max": 90}'::jsonb)
            ON CONFLICT (code) DO NOTHING;

            CREATE OR REPLACE FUNCTION open_support_ticket(p_subject text, p_body text, p_opened_by_email text) RETURNS uuid
              LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $f$
            DECLARE t uuid := app_tenant_id(); v uuid;
            BEGIN
              IF t IS NULL THEN
                RAISE EXCEPTION 'sin organización activa';
              END IF;
              INSERT INTO support_tickets (tenant_id, opened_by_email, subject, body) VALUES (t, p_opened_by_email, left(p_subject, 200), p_body) RETURNING id INTO v;
              RETURN v;
            END
            $f$;
            REVOKE ALL ON FUNCTION open_support_ticket(text, text, text) FROM PUBLIC;
            CREATE OR REPLACE FUNCTION support_ticket_status(p_id uuid) RETURNS text
              LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $f$
              SELECT status FROM support_tickets WHERE id = p_id AND tenant_id = app_tenant_id()
            $f$;
            REVOKE ALL ON FUNCTION support_ticket_status(uuid) FROM PUBLIC;
            SQL);
        if (DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'gestia_app'") !== null) {
            DB::unprepared('GRANT EXECUTE ON FUNCTION open_support_ticket(text, text, text) TO gestia_app; GRANT EXECUTE ON FUNCTION support_ticket_status(uuid) TO gestia_app;');
        }

        app(ProductHelpArticles::class)->syncBundled();
    }

    public function down(): void
    {
        // Las versiones publicadas de la ayuda son inmutables; los cambios de esquema son aditivos.
    }
};
