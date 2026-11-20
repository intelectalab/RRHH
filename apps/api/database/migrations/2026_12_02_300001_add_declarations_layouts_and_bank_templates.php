<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-1 a DEC-6 · Declaraciones y archivos: autor, aceptación, observación, motivo de rectificación y constancia de
 * cada declaración; plantillas de archivo bancario como datos (bank_file_templates); código de cada AFP en AFPnet;
 * y las estructuras oficiales de PLAME, T-Registro y AFPnet (sin verificar, AR-11) cargadas del paquete de país.
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS created_by_user_id uuid;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS accepted_at timestamptz;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS observation text;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS rectification_reason text;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS submission_document_id uuid;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_statutory_filings_created_by_user_id') THEN
                ALTER TABLE statutory_filings ADD CONSTRAINT fk_statutory_filings_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_statutory_filings_submission_document_id') THEN
                ALTER TABLE statutory_filings ADD CONSTRAINT fk_statutory_filings_submission_document_id FOREIGN KEY (tenant_id, submission_document_id) REFERENCES documents (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_statutory_filings_created_by_user_id ON statutory_filings (tenant_id, created_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_statutory_filings_submission_document_id ON statutory_filings (tenant_id, submission_document_id);

            CREATE TABLE IF NOT EXISTS bank_file_templates (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              code text NOT NULL,
              bank_code text NOT NULL,
              name text NOT NULL,
              kind text NOT NULL DEFAULT 'salary' CHECK (kind IN ('salary','cts','other')),
              definition jsonb NOT NULL,
              verified boolean NOT NULL DEFAULT false,
              status text NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
              source text,
              deleted_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_bank_file_templates_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_bank_file_templates_code UNIQUE (tenant_id, code),
              CONSTRAINT ck_bank_file_templates_1 CHECK (jsonb_typeof(definition) = 'object')
            );
            COMMENT ON TABLE bank_file_templates IS 'DEC-5 · Plantilla de archivo de pago de un banco (haberes, CTS y otros) definida como datos: separador o ancho fijo, cabecera, detalle y validación de cuentas. Las del paquete de país quedan por validar con cada banco.';
            ALTER TABLE bank_file_templates ENABLE ROW LEVEL SECURITY;
            ALTER TABLE bank_file_templates FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'bank_file_templates' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON bank_file_templates
                  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
                  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_bank_file_templates_touch') THEN
                CREATE TRIGGER trg_bank_file_templates_touch BEFORE UPDATE ON bank_file_templates FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON bank_file_templates TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON bank_file_templates TO gestia_platform;
              END IF;
            END $$;
            -- COL-6 · El aviso de códigos inválidos del perfil del registro laboral armaba mal la lista (concatenación
            -- de texto con un arreglo) y fallaba con un error de sintaxis de arreglo en lugar de nombrar los campos.
            CREATE OR REPLACE FUNCTION pe_validate_employee_profile() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
              v_bad text[] := '{}';
            BEGIN
              IF NOT pe_param_ok(8::smallint,  NEW.worker_type_code,           NEW.valid_from) THEN v_bad := array_append(v_bad, 'worker_type_code'); END IF;
              IF NOT pe_param_ok(33::smallint, NEW.labor_regime_sunat_code,    NEW.valid_from) THEN v_bad := array_append(v_bad, 'labor_regime_sunat_code'); END IF;
              IF NOT pe_param_ok(24::smallint, NEW.occupational_category_code, NEW.valid_from) THEN v_bad := array_append(v_bad, 'occupational_category_code'); END IF;
              IF NOT pe_param_ok(30::smallint, NEW.occupation_code,            NEW.valid_from) THEN v_bad := array_append(v_bad, 'occupation_code'); END IF;
              IF NOT pe_param_ok(9::smallint,  NEW.education_status_code,      NEW.valid_from) THEN v_bad := array_append(v_bad, 'education_status_code'); END IF;
              IF NOT pe_param_ok(12::smallint, NEW.contract_type_sunat_code,   NEW.valid_from) THEN v_bad := array_append(v_bad, 'contract_type_sunat_code'); END IF;
              IF NOT pe_param_ok(35::smallint, NEW.special_situation_code,     NEW.valid_from) THEN v_bad := array_append(v_bad, 'special_situation_code'); END IF;
              IF NOT pe_param_ok(16::smallint, NEW.payment_type_code,          NEW.valid_from) THEN v_bad := array_append(v_bad, 'payment_type_code'); END IF;
              IF NOT pe_param_ok(13::smallint, NEW.pay_periodicity_code,       NEW.valid_from) THEN v_bad := array_append(v_bad, 'pay_periodicity_code'); END IF;
              IF NOT pe_param_ok(15::smallint, NEW.worker_situation_code,      NEW.valid_from) THEN v_bad := array_append(v_bad, 'worker_situation_code'); END IF;
              IF NOT pe_param_ok(25::smallint, NEW.tax_treaty_code,            NEW.valid_from) THEN v_bad := array_append(v_bad, 'tax_treaty_code'); END IF;
              IF NOT pe_param_ok(14::smallint, NEW.eps_code,                   NEW.valid_from) THEN v_bad := array_append(v_bad, 'eps_code'); END IF;
              IF array_length(v_bad, 1) > 0 THEN
                RAISE EXCEPTION 'Códigos fuera de las tablas paramétricas vigentes: %', array_to_string(v_bad, ', ') USING ERRCODE = 'AY010';
              END IF;
              RETURN NEW;
            END
            $$;
            SQL);

        $path = base_path('packages/country-pe/data/estructuras_archivos_pe.json');
        if (is_file($path)) {
            /** @var array{codigos_afpnet: array<string, string>, estructuras: list<array<string, mixed>>} $data */
            $data = json_decode((string) file_get_contents($path), true);
            foreach ($data['codigos_afpnet'] as $fund => $code) {
                DB::table('pension_funds')->where('code', $fund)->whereNull('afpnet_code')->update(['afpnet_code' => $code]);
            }
            foreach ($data['estructuras'] as $e) {
                $exists = DB::table('statutory_file_layouts')->where('country_code', 'PE')->where('filing_kind', $e['filing_kind'])
                    ->where('structure_code', $e['structure_code'])->where('valid_from', $e['valid_from'])->exists();
                if ($exists) {
                    continue;
                }
                DB::table('statutory_file_layouts')->insert([
                    'country_code' => 'PE', 'filing_kind' => $e['filing_kind'], 'structure_code' => $e['structure_code'], 'name' => $e['name'],
                    'file_name_pattern' => $e['file_name_pattern'], 'file_extension' => $e['file_extension'], 'layout_version' => $e['layout_version'],
                    'field_separator' => $e['field_separator'], 'encoding' => $e['encoding'], 'line_ending' => $e['line_ending'],
                    'has_header' => $e['has_header'], 'trailing_separator' => $e['trailing_separator'],
                    'fields' => json_encode($e['fields'], JSON_UNESCAPED_UNICODE), 'source' => $e['source'], 'verified' => false, 'valid_from' => $e['valid_from'],
                ]);
            }
        }

        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void
    {
        // Las columnas, la tabla y las estructuras cargadas se conservan: retirarlas perdería declaraciones presentadas.
    }
};
