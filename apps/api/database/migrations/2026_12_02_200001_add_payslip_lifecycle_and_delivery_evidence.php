<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BOL-1 a BOL-5 · Ciclo de vida de la boleta (ME-20: generada, publicada, vista, con acuse, anulada), su
 * documento del legajo, versión y anulación; evidencia de entrega (IP y dispositivo del acuse, entrega en
 * físico con cargo escaneado); guarda de inmutabilidad del contenido; parámetros de la organización
 * (publicar al cerrar, exigir acuse, tolerancia de la explicación) y ayuda de las pantallas. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE payslips DROP CONSTRAINT IF EXISTS ck_payslips_1;
            ALTER TABLE payslips DROP CONSTRAINT IF EXISTS payslips_status_check;
            ALTER TABLE payslips ALTER COLUMN status SET DEFAULT 'generated';
            UPDATE payslips SET status = CASE WHEN status = 'closed' THEN 'published' ELSE 'generated' END WHERE status IN ('draft', 'closed');
            ALTER TABLE payslips ADD CONSTRAINT payslips_status_check CHECK (status IN ('generated','published','viewed','acknowledged','voided'));
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS version integer NOT NULL DEFAULT 1;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS document_id uuid;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS requires_acknowledgement boolean NOT NULL DEFAULT false;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS is_imported boolean NOT NULL DEFAULT false;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS published_at timestamptz;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS voided_at timestamptz;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS void_reason text;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS voided_by_user_id uuid;
            ALTER TABLE payslips ADD COLUMN IF NOT EXISTS generated_by_user_id uuid;

            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS first_viewed_ip text;
            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS acknowledged_ip text;
            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS acknowledged_user_agent text;
            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS last_reminded_at timestamptz;
            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS delivered_by_user_id uuid;
            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS evidence_document_id uuid;
            ALTER TABLE payslip_deliveries ADD COLUMN IF NOT EXISTS note text;

            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payslips_document_id') THEN
                ALTER TABLE payslips ADD CONSTRAINT fk_payslips_document_id FOREIGN KEY (tenant_id, document_id) REFERENCES documents (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payslips_voided_by_user_id') THEN
                ALTER TABLE payslips ADD CONSTRAINT fk_payslips_voided_by_user_id FOREIGN KEY (tenant_id, voided_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payslips_generated_by_user_id') THEN
                ALTER TABLE payslips ADD CONSTRAINT fk_payslips_generated_by_user_id FOREIGN KEY (tenant_id, generated_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payslip_deliveries_delivered_by_user_id') THEN
                ALTER TABLE payslip_deliveries ADD CONSTRAINT fk_payslip_deliveries_delivered_by_user_id FOREIGN KEY (tenant_id, delivered_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payslip_deliveries_evidence_document_id') THEN
                ALTER TABLE payslip_deliveries ADD CONSTRAINT fk_payslip_deliveries_evidence_document_id FOREIGN KEY (tenant_id, evidence_document_id) REFERENCES documents (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_payslips_document_id ON payslips (tenant_id, document_id);
            CREATE INDEX IF NOT EXISTS ix_payslips_voided_by_user_id ON payslips (tenant_id, voided_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payslips_generated_by_user_id ON payslips (tenant_id, generated_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payslip_deliveries_delivered_by_user_id ON payslip_deliveries (tenant_id, delivered_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_payslip_deliveries_evidence_document_id ON payslip_deliveries (tenant_id, evidence_document_id);

            CREATE OR REPLACE FUNCTION guard_closed_payslip() RETURNS trigger
            LANGUAGE plpgsql
            AS $f$
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'La boleta % no se borra: se anula y se emite otra versión.', OLD.number USING ERRCODE = 'AY002';
              END IF;
              IF OLD.closed_at IS NOT NULL AND (
                   NEW.content IS DISTINCT FROM OLD.content OR NEW.gross_amount IS DISTINCT FROM OLD.gross_amount
                OR NEW.deductions_amount IS DISTINCT FROM OLD.deductions_amount OR NEW.net_amount IS DISTINCT FROM OLD.net_amount
                OR NEW.employer_contributions IS DISTINCT FROM OLD.employer_contributions OR NEW.number IS DISTINCT FROM OLD.number
                OR NEW.file_id IS DISTINCT FROM OLD.file_id OR NEW.content_hash IS DISTINCT FROM OLD.content_hash
                OR NEW.employment_id IS DISTINCT FROM OLD.employment_id OR NEW.payroll_period_id IS DISTINCT FROM OLD.payroll_period_id
                OR NEW.version IS DISTINCT FROM OLD.version OR NEW.closed_at IS DISTINCT FROM OLD.closed_at) THEN
                RAISE EXCEPTION 'La boleta % está cerrada: su contenido no cambia. Emite una boleta de rectificación.', OLD.number
                  USING ERRCODE = 'AY002';
              END IF;
              IF OLD.status = 'voided' AND NEW.status IS DISTINCT FROM OLD.status THEN
                RAISE EXCEPTION 'La boleta % está anulada.', OLD.number USING ERRCODE = 'AY002';
              END IF;
              RETURN NEW;
            END
            $f$;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('boletas.publicar_al_cerrar', 'payroll', 'Publicar boletas al cerrar el periodo', 'BOL-1 · BOL-2 · RN-BOL-01: al cerrar el periodo las boletas generadas se publican solas en el portal y se avisa a cada trabajador; sin esto quedan generadas hasta que Payroll las publique.', 'boolean', 'true'::jsonb, ARRAY['organization','legal_entity'], NULL),
              ('boletas.exige_acuse', 'payroll', 'Exigir acuse de recibo de la boleta', 'BOL-2 · RN-BOL-03: la boleta publicada queda pendiente de acuse del trabajador y se le recuerda cada día hasta que lo dé. Política de la empresa.', 'boolean', 'false'::jsonb, ARRAY['organization','legal_entity'], NULL),
              ('boletas.tolerancia_explicacion', 'payroll', 'Diferencia de neto que se explica en la boleta', 'BOL-5: una diferencia de neto frente a la boleta anterior mayor que este monto lleva al menos un motivo explicado. 0: se explica toda diferencia. Política de la organización, no valor legal.', 'decimal', '0'::jsonb, ARRAY['organization','legal_entity'], '{"min": 0}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void
    {
        // Boletas y constancias de entrega son documentos de pago con conservación legal: no se revierten.
    }
};
