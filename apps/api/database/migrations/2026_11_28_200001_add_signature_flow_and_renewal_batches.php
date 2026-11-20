<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOC-6 · DOC-7 · DOC-8 · CON-5 · Flujo de firma con evidencia encadenada, nivel de firma por tipo documental
 * (ADR 0019) y renovación masiva con firma.
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
            ALTER TABLE signature_requests ADD COLUMN IF NOT EXISTS cancellation_reason text;
            ALTER TABLE signature_request_signers ADD COLUMN IF NOT EXISTS last_reminded_at timestamptz;
            ALTER TABLE signature_events ADD COLUMN IF NOT EXISTS previous_hash text;
            ALTER TABLE signature_events ADD COLUMN IF NOT EXISTS event_hash text;
            ALTER TABLE contract_renewal_batches ADD COLUMN IF NOT EXISTS sent_at timestamptz;
            ALTER TABLE contract_renewal_batches ADD COLUMN IF NOT EXISTS completed_at timestamptz;
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('firma.tipos_con_certificado', 'core', 'Tipos documentales que se firman con certificado', 'DOC-8 · ADR 0019 (D-10): códigos de tipo o carpeta documental que se firman con proveedor y certificado digital; el resto usa la firma electrónica propia con evidencia (OTP, IP, huella, sello de tiempo). Por defecto los contratos, sus adendas y acuerdos de teletrabajo (carpeta contracts). Por validar con el área legal (D-15).', 'list', '["contracts"]'::jsonb, ARRAY['organization'], NULL),
              ('firma.vigencia_dias', 'core', 'Días para firmar', 'DOC-6 · Días que tiene un envío a firma antes de vencer; vencido, se reenvía como un envío nuevo.', 'integer', '15'::jsonb, ARRAY['organization'], NULL),
              ('firma.recordatorio_dias', 'core', 'Recordatorio de firma pendiente', 'DOC-6 · Cada cuántos días se recuerda al firmante de turno que tiene un documento por firmar (0: sin recordatorios automáticos).', 'integer', '3'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
