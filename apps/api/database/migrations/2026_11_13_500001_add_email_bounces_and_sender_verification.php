<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NOT-3 · Rebotes de correo y remitente propio verificado; NOT-6 · canal para quien no tiene correo.
 *  - user_identities.bounced_at / bounce_reason: la dirección rebotó y no se vuelve a usar (RN-NOT-07).
 *  - message_channels.verification_status / verified_at / verification_error: remitente propio verificado.
 *  - Parámetro notificaciones.canal_sin_correo.
 * database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE user_identities ADD COLUMN IF NOT EXISTS bounced_at timestamptz;
            ALTER TABLE user_identities ADD COLUMN IF NOT EXISTS bounce_reason text;

            ALTER TABLE message_channels ADD COLUMN IF NOT EXISTS verification_status text NOT NULL DEFAULT 'not_required';
            ALTER TABLE message_channels ADD COLUMN IF NOT EXISTS verified_at timestamptz;
            ALTER TABLE message_channels ADD COLUMN IF NOT EXISTS verification_error text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_message_channels_verification_status')
                 AND NOT EXISTS (SELECT 1 FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid
                                 WHERE t.relname = 'message_channels' AND c.contype = 'c' AND pg_get_constraintdef(c.oid) LIKE '%verification_status%') THEN
                ALTER TABLE message_channels ADD CONSTRAINT ck_message_channels_verification_status
                  CHECK (verification_status IN ('not_required','pending','verified','failed'));
              END IF;
            END $$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('notificaciones.canal_sin_correo', 'base', 'Canal para quien no tiene correo', 'Canal externo por el que salen los avisos a quien no tiene correo válido (RN-NOT-07): un mensaje corto con el enlace, sin datos sensibles. none: solo en la aplicación.', 'select', '"sms"'::jsonb, ARRAY['organization'], '["sms", "whatsapp", "none"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
