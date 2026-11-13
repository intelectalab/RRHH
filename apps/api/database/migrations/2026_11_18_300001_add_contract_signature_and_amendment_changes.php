<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CON-2 · CON-3 · Registro del contrato (firma con su origen, aprobación, motivo de término, aviso del fin
 * del periodo de prueba) y adendas con los cambios pactados y lo que aplicaron al firmarse (RN-CON-08).
 * Parámetros contratos.requiere_aprobacion y contratos.aviso_fin_prueba_dias.
 * kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS signed_at timestamptz;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS signature_origin text;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS approved_by_user_id uuid;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS approved_at timestamptz;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS termination_reason text;
            ALTER TABLE contracts ADD COLUMN IF NOT EXISTS probation_alerted_on date;

            ALTER TABLE contract_amendments ADD COLUMN IF NOT EXISTS changes jsonb;
            ALTER TABLE contract_amendments ADD COLUMN IF NOT EXISTS applied_refs jsonb;
            ALTER TABLE contract_amendments ADD COLUMN IF NOT EXISTS signed_at timestamptz;
            ALTER TABLE contract_amendments ADD COLUMN IF NOT EXISTS signature_origin text;
            ALTER TABLE contract_amendments ADD COLUMN IF NOT EXISTS cancellation_reason text;

            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'contracts_signature_origin_check') THEN
                ALTER TABLE contracts ADD CONSTRAINT contracts_signature_origin_check CHECK (signature_origin IN ('physical_scan','electronic'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'contract_amendments_signature_origin_check') THEN
                ALTER TABLE contract_amendments ADD CONSTRAINT contract_amendments_signature_origin_check CHECK (signature_origin IN ('physical_scan','electronic'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_contracts_approved_by_user_id') THEN
                ALTER TABLE contracts ADD CONSTRAINT fk_contracts_approved_by_user_id FOREIGN KEY (tenant_id, approved_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_contracts_approved_by_user_id ON contracts (tenant_id, approved_by_user_id);

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('contratos.requiere_aprobacion', 'core', 'Contratos con aprobación antes de la firma', 'CON-2 · ME-11: con «sí», el contrato y la renovación pasan por aprobación (Borrador → En aprobación → En firma); con «no», al enviarlos se genera el documento y quedan listos para firmar.', 'boolean', 'true'::jsonb, ARRAY['organization','legal_entity'], NULL),
              ('contratos.aviso_fin_prueba_dias', 'core', 'Aviso del fin del periodo de prueba (días)', 'CON-2: días antes del fin del periodo de prueba en que se avisa al jefe y a RRHH. 0: el mismo día.', 'integer', '7'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0, "max": 60}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
