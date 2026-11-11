<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NOT-5 · Capa de mensajería SMS/WhatsApp: aprobación de plantillas por el proveedor y registro de
 * mensajes directos (propósito, plantilla, canal de respaldo y clave de idempotencia).
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
            ALTER TABLE notification_templates ADD COLUMN IF NOT EXISTS approval_status text NOT NULL DEFAULT 'not_required';
            ALTER TABLE notification_templates ADD COLUMN IF NOT EXISTS provider_template_ref text;
            ALTER TABLE notification_templates ADD COLUMN IF NOT EXISTS approval_requested_at timestamptz;
            ALTER TABLE notification_templates ADD COLUMN IF NOT EXISTS approval_decided_at timestamptz;
            ALTER TABLE notification_templates ADD COLUMN IF NOT EXISTS rejection_reason text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_notification_templates_approval_status') THEN
                ALTER TABLE notification_templates ADD CONSTRAINT ck_notification_templates_approval_status
                  CHECK (approval_status IN ('not_required','draft','pending','approved','rejected'));
              END IF;
            END $$;

            ALTER TABLE notification_deliveries ADD COLUMN IF NOT EXISTS purpose text;
            ALTER TABLE notification_deliveries ADD COLUMN IF NOT EXISTS template_code text;
            ALTER TABLE notification_deliveries ADD COLUMN IF NOT EXISTS fallback_from text;
            ALTER TABLE notification_deliveries ADD COLUMN IF NOT EXISTS idempotency_key text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_notification_deliveries_idempotency_key') THEN
                ALTER TABLE notification_deliveries ADD CONSTRAINT uq_notification_deliveries_idempotency_key UNIQUE (tenant_id, idempotency_key);
              END IF;
            END $$;
            SQL);
    }

    public function down(): void {}
};
