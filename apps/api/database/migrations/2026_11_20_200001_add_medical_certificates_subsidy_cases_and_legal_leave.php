<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUS-3 · AUS-4 · Certificado médico por ausencia (tipo CITT o particular, días otorgados, origen laboral),
 * expedientes de subsidio con su reembolso, y en la ausencia las ampliaciones de ley elegidas y la fecha de
 * retorno. kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente
 * (idempotente). Los parámetros legales nuevos llegan con gestia:legal-parameters:sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS legal_extensions jsonb;
            ALTER TABLE absence_requests ADD COLUMN IF NOT EXISTS return_on date;

            ALTER TABLE absence_medical_certificates ADD COLUMN IF NOT EXISTS kind text NOT NULL DEFAULT 'citt';
            ALTER TABLE absence_medical_certificates ADD COLUMN IF NOT EXISTS days_granted integer;
            ALTER TABLE absence_medical_certificates ADD COLUMN IF NOT EXISTS is_work_related boolean NOT NULL DEFAULT false;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'absence_medical_certificates_kind_check') THEN
                ALTER TABLE absence_medical_certificates ADD CONSTRAINT absence_medical_certificates_kind_check CHECK (kind IN ('citt','private'));
              END IF;
            END $$;
            DROP INDEX IF EXISTS ix_absence_medical_certificates_absence_request_id;
            CREATE UNIQUE INDEX IF NOT EXISTS uq_absence_medical_certificates_absence_request_id ON absence_medical_certificates (tenant_id, absence_request_id) WHERE deleted_at IS NULL;
            COMMENT ON TABLE absence_medical_certificates IS 'Certificado médico de un descanso; diagnóstico y número cifrados. AUS-3: uno por ausencia (CITT de EsSalud o certificado particular), con los días que otorga y si el descanso viene de un accidente o enfermedad de trabajo (SCTR). -- SENSIBLE: acceso por permiso de campo y registrado en data_access_logs.';

            CREATE TABLE IF NOT EXISTS absence_subsidy_cases (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              absence_request_id uuid NOT NULL,
              employment_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('illness','maternity')),
              status text NOT NULL DEFAULT 'pending_filing' CHECK (status IN ('pending_filing','filed','observed','approved','reimbursed','rejected','cancelled')),
              subsidized_days numeric(6,2) NOT NULL,
              starts_on date,
              ends_on date,
              year smallint,
              filing_due_on date,
              filed_on date,
              filing_reference text,
              amount_requested numeric(14,2),
              amount_approved numeric(14,2),
              amount_reimbursed numeric(14,2),
              currency char(3),
              reimbursed_on date,
              observation text,
              decision_reason text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_absence_subsidy_cases_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_absence_subsidy_cases_absence_request_id FOREIGN KEY (tenant_id, absence_request_id) REFERENCES absence_requests (tenant_id, id),
              CONSTRAINT fk_absence_subsidy_cases_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id)
            );
            COMMENT ON TABLE absence_subsidy_cases IS 'Expediente de subsidio de un descanso (AUS-3): los días que pasan a subsidio (incapacidad desde el umbral a cargo del empleador o maternidad completa) y el seguimiento del reembolso ante la seguridad social. Estados por acciones: por presentar → presentado → observado / aprobado → reembolsado, o rechazado.';
            CREATE UNIQUE INDEX IF NOT EXISTS uq_absence_subsidy_cases_absence_request_id ON absence_subsidy_cases (tenant_id, absence_request_id) WHERE deleted_at IS NULL;
            CREATE INDEX IF NOT EXISTS ix_absence_subsidy_cases_employment_id ON absence_subsidy_cases (tenant_id, employment_id);
            CREATE INDEX IF NOT EXISTS ix_absence_subsidy_cases_status_filing_due_on ON absence_subsidy_cases (tenant_id, status, filing_due_on);
            ALTER TABLE absence_subsidy_cases ENABLE ROW LEVEL SECURITY;
            ALTER TABLE absence_subsidy_cases FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON absence_subsidy_cases;
            CREATE POLICY tenant_isolation ON absence_subsidy_cases USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            DROP TRIGGER IF EXISTS trg_absence_subsidy_cases_touch ON absence_subsidy_cases;
            CREATE TRIGGER trg_absence_subsidy_cases_touch BEFORE UPDATE ON absence_subsidy_cases FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON absence_subsidy_cases TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON absence_subsidy_cases TO gestia_platform;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: guarda certificados y expedientes reales.
    }
};
