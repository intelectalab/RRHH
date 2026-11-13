<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COL-6 · COL-7 · COL-8 · Derechohabientes (fecha de la alerta de mayoría de edad), plan de salud del
 * régimen del trabajador, declaraciones de beneficiarios de Vida Ley con su archivo firmado, cuentas de
 * pago por relación laboral con vigencia y aprobación del cambio (RN-COL-11), catálogo de
 * administradoras de pensiones del Perú y el parámetro que exige la aprobación de la cuenta.
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
            ALTER TABLE dependents ADD COLUMN IF NOT EXISTS majority_alerted_on date;
            ALTER TABLE employment_labor_regimes ADD COLUMN IF NOT EXISTS health_plan_code text;

            ALTER TABLE insurance_beneficiaries ADD COLUMN IF NOT EXISTS declaration_id uuid;
            UPDATE insurance_beneficiaries SET declaration_id = id WHERE declaration_id IS NULL;
            ALTER TABLE insurance_beneficiaries ALTER COLUMN declaration_id SET NOT NULL;
            ALTER TABLE insurance_beneficiaries ADD COLUMN IF NOT EXISTS declaration_file_id uuid;
            ALTER TABLE insurance_beneficiaries ADD COLUMN IF NOT EXISTS signed_on date;
            ALTER TABLE insurance_beneficiaries ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'current';
            CREATE INDEX IF NOT EXISTS ix_insurance_beneficiaries_declaration_file_id ON insurance_beneficiaries (tenant_id, declaration_file_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'insurance_beneficiaries_status_check') THEN
                ALTER TABLE insurance_beneficiaries ADD CONSTRAINT insurance_beneficiaries_status_check CHECK (status IN ('current','superseded'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_insurance_beneficiaries_1') THEN
                ALTER TABLE insurance_beneficiaries ADD CONSTRAINT ck_insurance_beneficiaries_1 CHECK (percentage IS NULL OR (percentage > 0 AND percentage <= 100));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_insurance_beneficiaries_declaration_file_id') THEN
                ALTER TABLE insurance_beneficiaries ADD CONSTRAINT fk_insurance_beneficiaries_declaration_file_id FOREIGN KEY (tenant_id, declaration_file_id) REFERENCES stored_files (tenant_id, id);
              END IF;
            END $$;

            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS employment_id uuid;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'person_bank_accounts' AND column_name = 'status') THEN
                ALTER TABLE person_bank_accounts ADD COLUMN status text NOT NULL DEFAULT 'pending_approval';
                -- Cuentas cargadas antes de COL-8: rigen como estaban (activas o no), sin pasar por aprobación.
                UPDATE person_bank_accounts SET status = CASE WHEN is_active THEN 'active' ELSE 'cancelled' END;
              END IF;
            END $$;
            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS requested_by_user_id uuid;
            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS decided_by_user_id uuid;
            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS decided_at timestamptz;
            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS decision_reason text;
            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS valid_from date;
            ALTER TABLE person_bank_accounts ADD COLUMN IF NOT EXISTS valid_to date;
            -- Cuentas anteriores a COL-8 (sin relación laboral): se atan a la relación más reciente de la persona.
            UPDATE person_bank_accounts a SET employment_id = (
              SELECT e.id FROM employments e WHERE e.person_id = a.person_id AND e.tenant_id = a.tenant_id ORDER BY e.hire_date DESC LIMIT 1)
            WHERE a.employment_id IS NULL;
            UPDATE person_bank_accounts SET valid_from = created_at::date WHERE valid_from IS NULL;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM person_bank_accounts WHERE employment_id IS NULL) THEN
                ALTER TABLE person_bank_accounts ALTER COLUMN employment_id SET NOT NULL;
              END IF;
            END $$;
            ALTER TABLE person_bank_accounts ALTER COLUMN valid_from SET NOT NULL;
            CREATE INDEX IF NOT EXISTS ix_person_bank_accounts_requested_by_user_id ON person_bank_accounts (tenant_id, requested_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_person_bank_accounts_decided_by_user_id ON person_bank_accounts (tenant_id, decided_by_user_id);
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'person_bank_accounts_status_check') THEN
                ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_status_check CHECK (status IN ('pending_approval','active','rejected','cancelled'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_person_bank_accounts_vigencia') THEN
                ALTER TABLE person_bank_accounts ADD CONSTRAINT ck_person_bank_accounts_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ex_person_bank_accounts_vigencia') THEN
                ALTER TABLE person_bank_accounts ADD CONSTRAINT ex_person_bank_accounts_vigencia EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, purpose WITH =, daterange(valid_from, valid_to, '[]') WITH &&) WHERE (deleted_at IS NULL AND status = 'active') DEFERRABLE INITIALLY IMMEDIATE;
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_person_bank_accounts_employment_id') THEN
                ALTER TABLE person_bank_accounts ADD CONSTRAINT fk_person_bank_accounts_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_person_bank_accounts_requested_by_user_id') THEN
                ALTER TABLE person_bank_accounts ADD CONSTRAINT fk_person_bank_accounts_requested_by_user_id FOREIGN KEY (tenant_id, requested_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_person_bank_accounts_decided_by_user_id') THEN
                ALTER TABLE person_bank_accounts ADD CONSTRAINT fk_person_bank_accounts_decided_by_user_id FOREIGN KEY (tenant_id, decided_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            COMMENT ON TABLE person_bank_accounts IS 'Cuentas de pago de haberes y depósito de CTS por relación laboral y finalidad, con vigencia (COL-8). Un cambio queda pendiente hasta que otra persona lo aprueba (RN-COL-11); la exclusión solo rige entre cuentas activas. -- SENSIBLE: acceso por permiso de campo y registrado en data_access_logs.';

            INSERT INTO pension_funds (code, country_code, name, kind, status) VALUES
              ('pe_onp', 'PE', 'Oficina de Normalización Previsional (ONP)', 'public', 'active'),
              ('pe_afp_habitat', 'PE', 'AFP Habitat', 'private', 'active'),
              ('pe_afp_integra', 'PE', 'AFP Integra', 'private', 'active'),
              ('pe_afp_prima', 'PE', 'Prima AFP', 'private', 'active'),
              ('pe_afp_profuturo', 'PE', 'Profuturo AFP', 'private', 'active')
            ON CONFLICT (code) DO NOTHING;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options)
            SELECT 'colaboradores.cuenta_bancaria_requiere_aprobacion', 'core', 'Cambio de cuenta de pago con aprobación',
              'COL-8 · RN-COL-11: una cuenta nueva o cambiada queda pendiente hasta que otra persona con permiso la aprueba; sin aprobación la registra activa quien la carga.',
              'boolean', 'true'::jsonb, ARRAY['organization','legal_entity'], NULL
            WHERE NOT EXISTS (SELECT 1 FROM parameter_definitions WHERE code = 'colaboradores.cuenta_bancaria_requiere_aprobacion');
        SQL);
    }

    public function down(): void
    {
        // Sin reversa: las columnas y catálogos guardan historia laboral y de pagos.
    }
};
