<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUS-1 · Tipos de ausencia con reglas propias. Las reglas (goce, unidad, tope, sustento, quién
 * solicita, flujo de aprobación, efecto en planilla, alcance por empresa o régimen) pasan a
 * absence_type_rules con vigencia; absence_types queda con la identidad del tipo.
 * kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una base existente
 * (idempotente). absence_types no tenía uso todavía, por eso sus columnas de reglas se retiran.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE absence_types ADD COLUMN IF NOT EXISTS country_code text;
            ALTER TABLE absence_types ADD COLUMN IF NOT EXISTS is_system boolean NOT NULL DEFAULT false;
            ALTER TABLE absence_types ADD COLUMN IF NOT EXISTS is_confidential boolean NOT NULL DEFAULT false;
            ALTER TABLE absence_types ADD COLUMN IF NOT EXISTS legal_duration_parameter_code text;
            ALTER TABLE absence_types ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active';
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_absence_types_country_code') THEN
                ALTER TABLE absence_types ADD CONSTRAINT fk_absence_types_country_code FOREIGN KEY (country_code) REFERENCES countries (code);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'absence_types_status_check') THEN
                ALTER TABLE absence_types ADD CONSTRAINT absence_types_status_check CHECK (status IN ('active','archived'));
              END IF;
            END $$;
            DROP INDEX IF EXISTS ix_absence_types_payroll_concept_id;
            DROP INDEX IF EXISTS ix_absence_types_approval_workflow_definition_id;
            ALTER TABLE absence_types DROP CONSTRAINT IF EXISTS fk_absence_types_approval_workflow_definition_id;
            ALTER TABLE absence_types
              DROP COLUMN IF EXISTS is_paid, DROP COLUMN IF EXISTS affects_attendance, DROP COLUMN IF EXISTS requires_document,
              DROP COLUMN IF EXISTS max_days_per_year, DROP COLUMN IF EXISTS unit, DROP COLUMN IF EXISTS payroll_concept_id,
              DROP COLUMN IF EXISTS statutory_suspension_code, DROP COLUMN IF EXISTS subsidy_kind,
              DROP COLUMN IF EXISTS counts_as_worked_for_benefits, DROP COLUMN IF EXISTS approval_workflow_definition_id,
              DROP COLUMN IF EXISTS rules;
            COMMENT ON TABLE absence_types IS 'Tipo de ausencia configurable (descanso médico, licencias, permiso personal, compensación, trabajo remoto…). Las reglas viven en absence_type_rules con vigencia; los del paquete de país (is_system) no se editan.';

            CREATE TABLE IF NOT EXISTS absence_type_rules (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              absence_type_id uuid NOT NULL,
              is_paid boolean NOT NULL DEFAULT true,
              affects_attendance boolean NOT NULL DEFAULT true,
              unit text NOT NULL DEFAULT 'days' CHECK (unit IN ('days','hours')),
              max_quantity numeric(8,2),
              max_period text NOT NULL DEFAULT 'year' CHECK (max_period IN ('event','month','year')),
              min_notice_days integer,
              requires_document boolean NOT NULL DEFAULT false,
              document_grace_days integer,
              requestable_by text NOT NULL DEFAULT 'employee' CHECK (requestable_by IN ('employee','manager','hr')),
              approval_workflow_definition_id uuid,
              payroll_concept_code text,
              statutory_suspension_code text,
              subsidy_kind text NOT NULL DEFAULT 'none' CHECK (subsidy_kind IN ('none','illness','maternity')),
              counts_as_worked_for_benefits boolean NOT NULL DEFAULT true,
              legal_entity_ids jsonb,
              labor_regime_codes jsonb,
              valid_from date NOT NULL,
              valid_to date,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_absence_type_rules_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_absence_type_rules_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from),
              CONSTRAINT ex_absence_type_rules_vigencia EXCLUDE USING gist (tenant_id WITH =, absence_type_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&) DEFERRABLE INITIALLY IMMEDIATE,
              CONSTRAINT fk_absence_type_rules_absence_type_id FOREIGN KEY (tenant_id, absence_type_id) REFERENCES absence_types (tenant_id, id),
              CONSTRAINT fk_absence_type_rules_approval_workflow_definition_id FOREIGN KEY (tenant_id, approval_workflow_definition_id) REFERENCES workflow_definitions (tenant_id, id)
            );
            COMMENT ON TABLE absence_type_rules IS 'Reglas de un tipo de ausencia con vigencia (AUS-1): goce, unidad, tope por periodo, anticipación, sustento, quién la solicita, flujo de aprobación, efecto en planilla y alcance por empresa o régimen. Cambiar una regla cierra la fila vigente e inserta otra.';
            CREATE INDEX IF NOT EXISTS ix_absence_type_rules_approval_workflow_definition_id ON absence_type_rules (tenant_id, approval_workflow_definition_id);
            ALTER TABLE absence_type_rules ENABLE ROW LEVEL SECURITY;
            ALTER TABLE absence_type_rules FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policy WHERE polname = 'tenant_isolation' AND polrelid = 'absence_type_rules'::regclass) THEN
                CREATE POLICY tenant_isolation ON absence_type_rules USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_absence_type_rules_touch') THEN
                CREATE TRIGGER trg_absence_type_rules_touch BEFORE UPDATE ON absence_type_rules FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON absence_type_rules TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON absence_type_rules TO gestia_platform;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void {}
};
