<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WEB-5 a WEB-7 · Registro con verificación de correo, idempotencia de los formularios del sitio y cola de correos
 * del sitio (web_messages: el «outbox» de la plataforma, que no tiene organización).
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
            ALTER TABLE web_leads ADD COLUMN IF NOT EXISTS message text;
            ALTER TABLE web_leads ADD COLUMN IF NOT EXISTS idempotency_key text;
            ALTER TABLE web_signups ADD COLUMN IF NOT EXISTS verification_code_hash text;
            ALTER TABLE web_signups ADD COLUMN IF NOT EXISTS code_expires_at timestamptz;
            ALTER TABLE web_signups ADD COLUMN IF NOT EXISTS code_attempts integer NOT NULL DEFAULT 0;
            ALTER TABLE web_signups ADD COLUMN IF NOT EXISTS email_verified_at timestamptz;
            ALTER TABLE web_signups ADD COLUMN IF NOT EXISTS locale text;
            ALTER TABLE web_signups ADD COLUMN IF NOT EXISTS idempotency_key text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_web_leads_idempotency_key') THEN
                ALTER TABLE web_leads ADD CONSTRAINT uq_web_leads_idempotency_key UNIQUE (idempotency_key);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_web_signups_idempotency_key') THEN
                ALTER TABLE web_signups ADD CONSTRAINT uq_web_signups_idempotency_key UNIQUE (idempotency_key);
              END IF;
            END $$;

            CREATE TABLE IF NOT EXISTS web_messages (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              kind text NOT NULL,
              to_email text NOT NULL,
              locale text,
              subject text NOT NULL,
              body text NOT NULL,
              ref_type text NOT NULL,
              ref_id uuid NOT NULL,
              status text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','sent','failed')),
              attempts integer NOT NULL DEFAULT 0,
              last_error text,
              sent_at timestamptz,
              provider_ref text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now()
            );
            COMMENT ON TABLE web_messages IS 'Correos del sitio (código de verificación, confirmación y recordatorio de demo, aviso de lista de espera) en cola, enviados por el puerto de correo y reintentables (WEB-5 a WEB-7). Hace de outbox de la plataforma: no hay organización.';
            CREATE INDEX IF NOT EXISTS ix_web_messages_status ON web_messages (status);
            CREATE INDEX IF NOT EXISTS ix_web_messages_ref_type_ref_id ON web_messages (ref_type, ref_id);
            DROP TRIGGER IF EXISTS trg_web_messages_touch ON web_messages;
            CREATE TRIGGER trg_web_messages_touch BEFORE UPDATE ON web_messages FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);

        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'gestia_app'") !== null) {
            DB::statement('REVOKE ALL ON web_messages FROM gestia_app');
        }
        if (DB::selectOne("SELECT 1 AS x FROM pg_roles WHERE rolname = 'gestia_platform'") !== null) {
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON web_messages TO gestia_platform');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS web_messages;
            ALTER TABLE web_signups DROP CONSTRAINT IF EXISTS uq_web_signups_idempotency_key;
            ALTER TABLE web_leads DROP CONSTRAINT IF EXISTS uq_web_leads_idempotency_key;
            ALTER TABLE web_signups DROP COLUMN IF EXISTS idempotency_key, DROP COLUMN IF EXISTS locale, DROP COLUMN IF EXISTS email_verified_at,
              DROP COLUMN IF EXISTS code_attempts, DROP COLUMN IF EXISTS code_expires_at, DROP COLUMN IF EXISTS verification_code_hash;
            ALTER TABLE web_leads DROP COLUMN IF EXISTS idempotency_key, DROP COLUMN IF EXISTS message;
            SQL);
    }
};
