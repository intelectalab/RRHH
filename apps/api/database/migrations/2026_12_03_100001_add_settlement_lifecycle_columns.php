<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LIQ-1 a LIQ-4 · Liquidación de beneficios sociales: datos de entrada (indemnización, remuneración pendiente,
 * descuentos), resultado del motor (totales, periodos, errores y avisos), autoría de cada paso, plazo de pago avisado,
 * número de cálculo en la liquidación y sus líneas (los cálculos anteriores se conservan), y estado de firma de los
 * documentos de cese.
 *
 * kit/database/schema.sql lo trae para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE settlements
              ADD COLUMN IF NOT EXISTS severance_applies boolean NOT NULL DEFAULT false,
              ADD COLUMN IF NOT EXISTS include_pending_salary boolean NOT NULL DEFAULT false,
              ADD COLUMN IF NOT EXISTS inputs jsonb,
              ADD COLUMN IF NOT EXISTS totals jsonb,
              ADD COLUMN IF NOT EXISTS periods jsonb,
              ADD COLUMN IF NOT EXISTS errors jsonb,
              ADD COLUMN IF NOT EXISTS warnings jsonb,
              ADD COLUMN IF NOT EXISTS calculated_at timestamptz,
              ADD COLUMN IF NOT EXISTS calculated_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS submitted_at timestamptz,
              ADD COLUMN IF NOT EXISTS submitted_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS approved_at timestamptz,
              ADD COLUMN IF NOT EXISTS return_reason text,
              ADD COLUMN IF NOT EXISTS void_reason text,
              ADD COLUMN IF NOT EXISTS payment_reference text,
              ADD COLUMN IF NOT EXISTS paid_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS due_alerted_at timestamptz,
              ADD COLUMN IF NOT EXISTS documents_delivered_at timestamptz,
              ADD COLUMN IF NOT EXISTS calculation_number integer;
            ALTER TABLE settlement_lines
              ADD COLUMN IF NOT EXISTS concept_code text,
              ADD COLUMN IF NOT EXISTS concept_name text,
              ADD COLUMN IF NOT EXISTS concept_kind text,
              ADD COLUMN IF NOT EXISTS explanation text,
              ADD COLUMN IF NOT EXISTS calculation_number integer;
            ALTER TABLE termination_documents
              ADD COLUMN IF NOT EXISTS title text NOT NULL DEFAULT '',
              ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'generated',
              ADD COLUMN IF NOT EXISTS signature_request_id uuid,
              ADD COLUMN IF NOT EXISTS signed_at timestamptz;
            ALTER TABLE termination_documents ALTER COLUMN title DROP DEFAULT;
            DO $$
            DECLARE r record;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'termination_documents_status_check') THEN
                ALTER TABLE termination_documents ADD CONSTRAINT termination_documents_status_check
                  CHECK (status IN ('generated','pending_signature','signed','declined'));
              END IF;
              FOR r IN SELECT * FROM (VALUES
                ('settlements', 'calculated_by_user_id', 'users'), ('settlements', 'submitted_by_user_id', 'users'),
                ('settlements', 'paid_by_user_id', 'users'), ('termination_documents', 'signature_request_id', 'signature_requests')) AS t(tbl, col, ref)
              LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_' || r.tbl || '_' || r.col) THEN
                  EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (tenant_id, %I) REFERENCES %I (tenant_id, id)', r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col, r.ref);
                END IF;
                EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (tenant_id, %I)', 'ix_' || r.tbl || '_' || r.col, r.tbl, r.col);
              END LOOP;
            END $$;
        SQL);
    }

    public function down(): void
    {
        // Columnas aditivas: no se quitan para no perder datos laborales.
    }
};
