<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUD-5 · Cadena de hash de la auditoría, verificaciones de integridad y manifiesto del archivado en frío.
 * AUD-3 · Parámetro de conservación de la actividad de usuarios (separado del de auditoría).
 * database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes (idempotente).
 * Las entradas de auditoría previas a esta migración se sellan una sola vez, en orden, al crear la cadena.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE audit_events ADD COLUMN IF NOT EXISTS chain_seq bigint;
            ALTER TABLE audit_events ADD COLUMN IF NOT EXISTS prev_hash text;
            ALTER TABLE audit_events ADD COLUMN IF NOT EXISTS row_hash text;
            CREATE INDEX IF NOT EXISTS ix_audit_events_chain_seq ON audit_events (tenant_id, chain_seq);

            CREATE TABLE IF NOT EXISTS audit_chain_heads (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              last_seq bigint NOT NULL DEFAULT 0,
              last_hash text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_audit_chain_heads_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_audit_chain_heads_r1 UNIQUE (tenant_id)
            );
            CREATE TABLE IF NOT EXISTS audit_integrity_checks (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              status text NOT NULL CHECK (status IN ('ok','failed')),
              checked_rows integer NOT NULL DEFAULT 0,
              archived_rows integer NOT NULL DEFAULT 0,
              unchained_rows integer NOT NULL DEFAULT 0,
              first_seq bigint,
              last_seq bigint,
              issues jsonb,
              started_at timestamptz NOT NULL,
              finished_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_audit_integrity_checks_tenant_id UNIQUE (tenant_id, id)
            );
            CREATE INDEX IF NOT EXISTS ix_audit_integrity_checks_started_at ON audit_integrity_checks (tenant_id, started_at);
            CREATE TABLE IF NOT EXISTS audit_archives (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              period_month date NOT NULL,
              from_seq bigint,
              to_seq bigint,
              row_count integer NOT NULL,
              first_prev_hash text,
              last_hash text,
              storage_disk text NOT NULL,
              storage_key text NOT NULL,
              sha256 text NOT NULL,
              size_bytes bigint,
              archived_at timestamptz NOT NULL DEFAULT now(),
              purge_after date NOT NULL,
              purged_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_audit_archives_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_audit_archives_period_month UNIQUE (tenant_id, period_month)
            );

            DO $$
            DECLARE t text;
            BEGIN
              FOREACH t IN ARRAY ARRAY['audit_chain_heads', 'audit_integrity_checks', 'audit_archives'] LOOP
                EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
                EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
                IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'tenant_isolation') THEN
                  EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid)', t);
                END IF;
              END LOOP;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_audit_chain_heads_touch') THEN
                CREATE TRIGGER trg_audit_chain_heads_touch BEFORE UPDATE ON audit_chain_heads FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_audit_archives_touch') THEN
                CREATE TRIGGER trg_audit_archives_touch BEFORE UPDATE ON audit_archives FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
            END $$;

            CREATE OR REPLACE FUNCTION audit_event_hash(p_tenant uuid, p_seq bigint, p_prev text, p_id uuid, p_occurred timestamptz,
              p_actor uuid, p_kind text, p_action text, p_entity_type text, p_entity_id uuid, p_before jsonb, p_after jsonb,
              p_fields text[], p_reason text, p_ip inet, p_user_agent text, p_request uuid) RETURNS text
            LANGUAGE sql IMMUTABLE
            AS $f$
              SELECT encode(sha256(convert_to(concat_ws(chr(31),
                p_tenant::text, p_seq::text, coalesce(p_prev, ''), p_id::text, ((extract(epoch FROM p_occurred) * 1000000)::bigint)::text,
                coalesce(p_actor::text, ''), coalesce(p_kind, ''), coalesce(p_action, ''), coalesce(p_entity_type, ''), coalesce(p_entity_id::text, ''),
                coalesce(p_before::text, ''), coalesce(p_after::text, ''), coalesce(array_to_string(p_fields, chr(30)), ''),
                coalesce(p_reason, ''), coalesce(host(p_ip), ''), coalesce(p_user_agent, ''), coalesce(p_request::text, '')), 'UTF8')), 'hex')
            $f$;

            CREATE OR REPLACE FUNCTION audit_events_chain() RETURNS trigger
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp
            AS $f$
            DECLARE
              v_seq bigint;
              v_prev text;
            BEGIN
              INSERT INTO audit_chain_heads (tenant_id, last_seq) VALUES (NEW.tenant_id, 0) ON CONFLICT (tenant_id) DO NOTHING;
              SELECT last_seq, last_hash INTO v_seq, v_prev FROM audit_chain_heads WHERE tenant_id = NEW.tenant_id FOR UPDATE;
              NEW.chain_seq := v_seq + 1;
              NEW.prev_hash := v_prev;
              NEW.row_hash := audit_event_hash(NEW.tenant_id, NEW.chain_seq, NEW.prev_hash, NEW.id, NEW.occurred_at, NEW.actor_user_id, NEW.actor_kind,
                NEW.action, NEW.entity_type, NEW.entity_id, NEW.before_data, NEW.after_data, NEW.changed_fields, NEW.reason, NEW.ip, NEW.user_agent, NEW.request_id);
              UPDATE audit_chain_heads SET last_seq = NEW.chain_seq, last_hash = NEW.row_hash, updated_at = now() WHERE tenant_id = NEW.tenant_id;
              RETURN NEW;
            END
            $f$;
            REVOKE ALL ON FUNCTION audit_events_chain() FROM PUBLIC;

            -- Sellado único de las entradas anteriores a la cadena (en orden de ocurrencia, por organización).
            DO $$
            DECLARE
              r record;
              v_tenant uuid := NULL;
              v_seq bigint := 0;
              v_prev text := NULL;
              v_hash text;
            BEGIN
              IF EXISTS (SELECT 1 FROM audit_events WHERE chain_seq IS NULL) THEN
                PERFORM set_config('session_replication_role', 'replica', true);
                FOR r IN SELECT * FROM audit_events WHERE chain_seq IS NULL ORDER BY tenant_id, occurred_at, id LOOP
                  IF v_tenant IS DISTINCT FROM r.tenant_id THEN
                    v_tenant := r.tenant_id;
                    SELECT coalesce(max(chain_seq), 0) INTO v_seq FROM audit_events WHERE tenant_id = r.tenant_id;
                    SELECT row_hash INTO v_prev FROM audit_events WHERE tenant_id = r.tenant_id AND chain_seq = v_seq;
                  END IF;
                  v_seq := v_seq + 1;
                  v_hash := audit_event_hash(r.tenant_id, v_seq, v_prev, r.id, r.occurred_at, r.actor_user_id, r.actor_kind, r.action,
                    r.entity_type, r.entity_id, r.before_data, r.after_data, r.changed_fields, r.reason, r.ip, r.user_agent, r.request_id);
                  UPDATE audit_events SET chain_seq = v_seq, prev_hash = v_prev, row_hash = v_hash WHERE id = r.id AND occurred_at = r.occurred_at;
                  v_prev := v_hash;
                  INSERT INTO audit_chain_heads (tenant_id, last_seq, last_hash) VALUES (r.tenant_id, v_seq, v_hash)
                    ON CONFLICT (tenant_id) DO UPDATE SET last_seq = EXCLUDED.last_seq, last_hash = EXCLUDED.last_hash, updated_at = now();
                END LOOP;
                PERFORM set_config('session_replication_role', 'origin', true);
              END IF;
            END $$;

            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_audit_events_chain' AND tgrelid = 'audit_events'::regclass) THEN
                CREATE TRIGGER trg_audit_events_chain BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION audit_events_chain();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT ON audit_chain_heads TO gestia_app;
                REVOKE INSERT, UPDATE, DELETE ON audit_chain_heads FROM gestia_app;
                GRANT SELECT, INSERT ON audit_integrity_checks, audit_archives TO gestia_app;
                REVOKE UPDATE, DELETE ON audit_archives, audit_integrity_checks FROM gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON audit_chain_heads, audit_integrity_checks, audit_archives TO gestia_platform;
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('privacidad.retencion_actividad_dias', 'base', 'Conservación de la actividad de usuarios (días)', 'Días que se conserva la actividad de los usuarios (ingresos, vistas, descargas, búsquedas). No afecta la auditoría ni los logs técnicos.', 'integer', '365'::jsonb, ARRAY['organization'], '{"min": 30, "max": 3650}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
