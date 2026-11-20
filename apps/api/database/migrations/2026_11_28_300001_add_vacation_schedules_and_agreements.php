<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VAC-5 · VAC-6 · Rol anual de vacaciones (campaña por empresa y año con sus líneas propuestas) y acuerdos
 * escritos de vacaciones (fraccionamiento, adelanto y venta de días).
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
            CREATE TABLE IF NOT EXISTS vacation_schedules (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              legal_entity_id uuid NOT NULL,
              year smallint NOT NULL,
              name text NOT NULL,
              status text NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
              opened_by_user_id uuid,
              closed_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_vacation_schedules_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_vacation_schedules_legal_entity_id_year UNIQUE (tenant_id, legal_entity_id, year),
              CONSTRAINT fk_vacation_schedules_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id),
              CONSTRAINT fk_vacation_schedules_opened_by_user_id FOREIGN KEY (tenant_id, opened_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE vacation_schedules IS 'Rol anual de vacaciones por empresa y año (VAC-5): RRHH abre la campaña, los jefes proponen fechas, cada trabajador confirma y lo confirmado se convierte en solicitudes aprobadas. Cerrar la campaña impide nuevas propuestas.';
            CREATE INDEX IF NOT EXISTS ix_vacation_schedules_opened_by_user_id ON vacation_schedules (tenant_id, opened_by_user_id);
            ALTER TABLE vacation_schedules ENABLE ROW LEVEL SECURITY;
            ALTER TABLE vacation_schedules FORCE ROW LEVEL SECURITY;

            CREATE TABLE IF NOT EXISTS vacation_schedule_lines (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              vacation_schedule_id uuid NOT NULL,
              employment_id uuid NOT NULL,
              starts_on date NOT NULL,
              ends_on date NOT NULL,
              days numeric(6,2),
              status text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed','confirmed','declined','converted','withdrawn')),
              proposed_by_user_id uuid,
              responded_at timestamptz,
              response_note text,
              vacation_request_id uuid,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_vacation_schedule_lines_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT ck_vacation_schedule_lines_1 CHECK (ends_on >= starts_on),
              CONSTRAINT fk_vacation_schedule_lines_vacation_schedule_id FOREIGN KEY (tenant_id, vacation_schedule_id) REFERENCES vacation_schedules (tenant_id, id),
              CONSTRAINT fk_vacation_schedule_lines_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_vacation_schedule_lines_proposed_by_user_id FOREIGN KEY (tenant_id, proposed_by_user_id) REFERENCES users (tenant_id, id),
              CONSTRAINT fk_vacation_schedule_lines_vacation_request_id FOREIGN KEY (tenant_id, vacation_request_id) REFERENCES vacation_requests (tenant_id, id)
            );
            COMMENT ON TABLE vacation_schedule_lines IS 'Fechas propuestas para un trabajador en el rol anual (VAC-5). Estados solo por acciones: propuesta, confirmada o declinada por el trabajador, convertida en solicitud o retirada.';
            CREATE INDEX IF NOT EXISTS ix_vacation_schedule_lines_employment_id ON vacation_schedule_lines (tenant_id, employment_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_schedule_lines_proposed_by_user_id ON vacation_schedule_lines (tenant_id, proposed_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_schedule_lines_vacation_request_id ON vacation_schedule_lines (tenant_id, vacation_request_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_schedule_lines_vacation_schedule_id_employment_id ON vacation_schedule_lines (tenant_id, vacation_schedule_id, employment_id);
            ALTER TABLE vacation_schedule_lines ENABLE ROW LEVEL SECURITY;
            ALTER TABLE vacation_schedule_lines FORCE ROW LEVEL SECURITY;

            CREATE TABLE IF NOT EXISTS vacation_agreements (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              employment_id uuid NOT NULL,
              kind text NOT NULL CHECK (kind IN ('fractionation','advance','sale')),
              vacation_accrual_period_id uuid,
              days numeric(6,2),
              effective_on date NOT NULL,
              status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','issued','active','cancelled')),
              document_id uuid,
              signed_at timestamptz,
              signature_origin text CHECK (signature_origin IN ('physical_scan','electronic')),
              payroll_concept_code text,
              ledger_entry_id uuid,
              created_by_user_id uuid,
              cancel_reason text,
              notes text,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              deleted_at timestamptz,
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_vacation_agreements_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_vacation_agreements_employment_id FOREIGN KEY (tenant_id, employment_id) REFERENCES employments (tenant_id, id),
              CONSTRAINT fk_vacation_agreements_vacation_accrual_period_id FOREIGN KEY (tenant_id, vacation_accrual_period_id) REFERENCES vacation_accrual_periods (tenant_id, id),
              CONSTRAINT fk_vacation_agreements_document_id FOREIGN KEY (tenant_id, document_id) REFERENCES documents (tenant_id, id),
              CONSTRAINT fk_vacation_agreements_ledger_entry_id FOREIGN KEY (tenant_id, ledger_entry_id) REFERENCES vacation_ledger_entries (tenant_id, id),
              CONSTRAINT fk_vacation_agreements_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE vacation_agreements IS 'Acuerdo escrito de vacaciones (VAC-6, DL 713 · DL 1405): fraccionamiento del descanso, adelanto a cuenta del periodo en curso o venta de días (reducción del descanso). Se emite en PDF al legajo, se registra firmado y entonces queda vigente; la venta firmada descuenta el saldo con un movimiento «sold» y da el concepto de planilla. Los límites salen de parámetros legales y de la política.';
            CREATE INDEX IF NOT EXISTS ix_vacation_agreements_vacation_accrual_period_id ON vacation_agreements (tenant_id, vacation_accrual_period_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_agreements_document_id ON vacation_agreements (tenant_id, document_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_agreements_ledger_entry_id ON vacation_agreements (tenant_id, ledger_entry_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_agreements_created_by_user_id ON vacation_agreements (tenant_id, created_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_vacation_agreements_employment_id_kind ON vacation_agreements (tenant_id, employment_id, kind);
            ALTER TABLE vacation_agreements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE vacation_agreements FORCE ROW LEVEL SECURITY;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'vacation_schedules' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON vacation_schedules USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_vacation_schedules_touch') THEN
                CREATE TRIGGER trg_vacation_schedules_touch BEFORE UPDATE ON vacation_schedules FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'vacation_schedule_lines' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON vacation_schedule_lines USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_vacation_schedule_lines_touch') THEN
                CREATE TRIGGER trg_vacation_schedule_lines_touch BEFORE UPDATE ON vacation_schedule_lines FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'vacation_agreements' AND policyname = 'tenant_isolation') THEN
                CREATE POLICY tenant_isolation ON vacation_agreements USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_vacation_agreements_touch') THEN
                CREATE TRIGGER trg_vacation_agreements_touch BEFORE UPDATE ON vacation_agreements FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE ON vacation_schedules, vacation_schedule_lines, vacation_agreements TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE ON vacation_schedules, vacation_schedule_lines, vacation_agreements TO gestia_platform;
              END IF;
            END $$;
            -- ADR 0021 · Los 30 días del régimen general rigen desde 2025 (como los de micro y pequeña empresa). La fila
            -- vacía «por validar» que dejó una sincronización anterior desde 2026 se adelanta para que la sincronización
            -- complete su valor sin crear una vigencia superpuesta. Solo toca la fila sin valor.
            UPDATE legal_parameters SET valid_from = '2025-01-01'
             WHERE country_code = 'PE' AND code = 'vacaciones_dias' AND labor_regime_code = 'pe_general' AND valid_from = '2026-01-01'
               AND value_numeric IS NULL AND value_json IS NULL
               AND NOT EXISTS (SELECT 1 FROM legal_parameters o WHERE o.country_code = 'PE' AND o.code = 'vacaciones_dias'
                                AND o.labor_regime_code = 'pe_general' AND o.valid_from < '2026-01-01');
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('vacaciones.aviso_vencimiento_dias', 'time_off', 'Aviso de periodos por vencer', 'VAC-5 · Días de anticipación con que se alerta al jefe y a RRHH que un periodo vacacional ganado vence sin gozarse (fecha límite de goce). Política de la organización, no valor legal.', 'integer', '60'::jsonb, ARRAY['organization','legal_entity'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);

        // Ayuda de las pantallas nuevas (solo crea lo que falta).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
