<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IAR-2 · IAR-9 · IAR-10 · Asistente de ayuda documental y bitácora de IA en el primer corte.
 *  - Los permisos ai.help_assistant.use, ai.use_case_settings.view/update y ai.usage_logs.view pasan al módulo
 *    core (se activan con la ayuda; el plan lo decide la bandera ai_help_assistant).
 *  - Parámetro ia.retencion_bitacora_dias y purga del contenido vencido de ai_interactions (purge_ai_interactions()),
 *    con un guardián que solo deja vaciar el contenido desde esa función.
 * kit/database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            UPDATE permissions SET module_code = 'core'
             WHERE code IN ('ai.help_assistant.use', 'ai.use_case_settings.view', 'ai.use_case_settings.update', 'ai.usage_logs.view');

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('ia.retencion_bitacora_dias', 'core', 'Retención del contenido de la bitácora de IA (días)', 'IAR-2 · RN-IAR: días que se conserva el texto cifrado de la pregunta y la respuesta de cada uso de IA. Pasado el plazo se vacía el contenido; la fila (quién, cuándo, propósito, consumo) se conserva.', 'integer', '365'::jsonb, ARRAY['organization'], '{"min": 30, "max": 3650}'::jsonb)
            ON CONFLICT (code) DO NOTHING;

            -- IAR-2 · Retención de la bitácora de IA (parámetro ia.retencion_bitacora_dias). La bitácora es de solo
            -- inserción; pasado el plazo solo se vacía el contenido cifrado (pregunta, respuesta y entidades citadas) y se
            -- conserva quién, cuándo, para qué y cuánto consumió. El único camino es purge_ai_interactions(), que corre con
            -- los privilegios del dueño y marca la transacción; cualquier otro UPDATE, DELETE o TRUNCATE sigue rechazado.
            CREATE OR REPLACE FUNCTION ai_interactions_guard() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
              IF TG_OP = 'UPDATE' AND current_setting('app.ai_purge', true) = 'on'
                 AND NEW.prompt_enc = '' AND NEW.response_enc IS NULL AND NEW.referenced_entities IS NULL
                 AND (NEW.id, NEW.tenant_id, NEW.user_id, NEW.use_case, NEW.data_scopes, NEW.provider, NEW.model, NEW.input_tokens, NEW.output_tokens, NEW.was_blocked, NEW.occurred_at)
                     IS NOT DISTINCT FROM (OLD.id, OLD.tenant_id, OLD.user_id, OLD.use_case, OLD.data_scopes, OLD.provider, OLD.model, OLD.input_tokens, OLD.output_tokens, OLD.was_blocked, OLD.occurred_at) THEN
                RETURN NEW;
              END IF;
              RAISE EXCEPTION 'La tabla % es de solo inserción: % no está permitido. Una corrección se registra como una fila nueva.', TG_TABLE_NAME, TG_OP
                USING ERRCODE = 'AY001';
            END
            $$;
            COMMENT ON FUNCTION ai_interactions_guard() IS 'IAR-2: bitácora de IA de solo inserción; solo purge_ai_interactions() vacía el contenido vencido.';
            CREATE OR REPLACE TRIGGER trg_ai_interactions_inmutable BEFORE UPDATE OR DELETE ON ai_interactions FOR EACH ROW EXECUTE FUNCTION ai_interactions_guard();

            CREATE OR REPLACE FUNCTION purge_ai_interactions(p_before timestamptz) RETURNS integer
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
            AS $$
            DECLARE n integer;
            BEGIN
              PERFORM set_config('app.ai_purge', 'on', true);
              UPDATE ai_interactions SET prompt_enc = '', response_enc = NULL, referenced_entities = NULL
               WHERE tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
                 AND occurred_at < p_before AND prompt_enc <> '';
              GET DIAGNOSTICS n = ROW_COUNT;
              PERFORM set_config('app.ai_purge', 'off', true);
              RETURN n;
            END
            $$;
            COMMENT ON FUNCTION purge_ai_interactions(timestamptz) IS 'IAR-2: vacía el contenido de la bitácora de IA de la organización activa anterior a la fecha (retención).';
            REVOKE ALL ON FUNCTION purge_ai_interactions(timestamptz) FROM PUBLIC;
            GRANT EXECUTE ON FUNCTION purge_ai_interactions(timestamptz) TO gestia_app;
            SQL);
    }

    public function down(): void {}
};
