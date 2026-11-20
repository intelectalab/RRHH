<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAR-1 · PAR-4 · PAR-6 · PAR-7 · Actualizaciones normativas de la consola con doble revisión, registro de novedades
 * normativas que leen las organizaciones, confirmación de un parámetro «por validar» y verificación de una tabla
 * salarial por convenio (las dos últimas de la organización, solo inserción).
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
            CREATE TABLE IF NOT EXISTS legal_parameter_publications (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              country_code text NOT NULL,
              title text NOT NULL,
              summary text,
              legal_source text NOT NULL,
              changes jsonb NOT NULL,
              status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','in_review','published','rejected')),
              prepared_by_platform_user_id uuid,
              reviewed_by_platform_user_id uuid,
              review_note text,
              submitted_at timestamptz,
              published_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_legal_parameter_publications_country_code FOREIGN KEY (country_code) REFERENCES countries (code),
              CONSTRAINT fk_legal_parameter_publications_prepared_by_platform_user_id FOREIGN KEY (prepared_by_platform_user_id) REFERENCES platform_users (id),
              CONSTRAINT fk_legal_parameter_publications_reviewed_by_platform_user_id FOREIGN KEY (reviewed_by_platform_user_id) REFERENCES platform_users (id)
            );
            COMMENT ON TABLE legal_parameter_publications IS 'PAR-4 · Actualización normativa preparada en la consola: cambios de parámetros legales de un país con su norma; la publica una segunda persona del equipo del producto (ADR 0023). Publicar inserta las vigencias nuevas y cierra las anteriores, nunca las modifica.';
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_publications_prepared_by_platform_user_id ON legal_parameter_publications (prepared_by_platform_user_id);
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_publications_reviewed_by_platform_user_id ON legal_parameter_publications (reviewed_by_platform_user_id);
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_publications_country_code_status ON legal_parameter_publications (country_code, status);

            CREATE TABLE IF NOT EXISTS legal_parameter_changes (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              legal_parameter_publication_id uuid,
              country_code text NOT NULL,
              labor_regime_code text,
              code text NOT NULL,
              name text NOT NULL,
              unit text NOT NULL,
              previous_value jsonb,
              new_value jsonb,
              valid_from date NOT NULL,
              valid_to date,
              legal_source text,
              origin text NOT NULL CHECK (origin IN ('publication','country_pack')),
              published_at timestamptz NOT NULL DEFAULT now(),
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT fk_legal_parameter_changes_legal_parameter_publication_id FOREIGN KEY (legal_parameter_publication_id) REFERENCES legal_parameter_publications (id),
              CONSTRAINT fk_legal_parameter_changes_country_code FOREIGN KEY (country_code) REFERENCES countries (code),
              CONSTRAINT fk_legal_parameter_changes_labor_regime_code FOREIGN KEY (labor_regime_code) REFERENCES labor_regimes (code)
            );
            COMMENT ON TABLE legal_parameter_changes IS 'PAR-7 · Registro de novedades normativas: cada vigencia nueva o valor completado de un parámetro legal, con el valor anterior y el nuevo, la norma y su origen (publicación de la consola o paquete de país). Lo leen las organizaciones del país.';
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_changes_legal_parameter_publication_id ON legal_parameter_changes (legal_parameter_publication_id);
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_changes_labor_regime_code ON legal_parameter_changes (labor_regime_code);
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_changes_country_code_published_at ON legal_parameter_changes (country_code, published_at);
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_changes_code ON legal_parameter_changes (code);

            CREATE TABLE IF NOT EXISTS legal_parameter_confirmations (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              legal_parameter_id uuid NOT NULL,
              parameter_code text NOT NULL,
              confirmed_by_user_id uuid NOT NULL,
              note text,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_legal_parameter_confirmations_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_legal_parameter_confirmations_confirmed_by_user_id FOREIGN KEY (tenant_id, confirmed_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE legal_parameter_confirmations IS 'PAR-1 · RN-PAR-03 · Confirmación expresa de la organización de un parámetro legal «por validar» antes de cerrar un periodo que lo usa (solo inserción).';
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_confirmations_confirmed_by_user_id ON legal_parameter_confirmations (tenant_id, confirmed_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_legal_parameter_confirmations_legal_parameter_id ON legal_parameter_confirmations (tenant_id, legal_parameter_id);

            CREATE TABLE IF NOT EXISTS wage_table_verifications (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              table_code text NOT NULL,
              labor_regime_code text NOT NULL,
              valid_from date NOT NULL,
              verified_by_user_id uuid NOT NULL,
              acta_reference text NOT NULL,
              note text,
              created_at timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT uq_wage_table_verifications_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT uq_wage_table_verifications_table_code_valid_from UNIQUE (tenant_id, table_code, valid_from),
              CONSTRAINT fk_wage_table_verifications_labor_regime_code FOREIGN KEY (labor_regime_code) REFERENCES labor_regimes (code),
              CONSTRAINT fk_wage_table_verifications_verified_by_user_id FOREIGN KEY (tenant_id, verified_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE wage_table_verifications IS 'PAR-6 · RN-PAR-10 · Verificación de la organización de una tabla salarial por convenio (lote de parámetros con la misma vigencia) contra el acta (solo inserción).';
            CREATE INDEX IF NOT EXISTS ix_wage_table_verifications_labor_regime_code ON wage_table_verifications (tenant_id, labor_regime_code);
            CREATE INDEX IF NOT EXISTS ix_wage_table_verifications_verified_by_user_id ON wage_table_verifications (tenant_id, verified_by_user_id);

            ALTER TABLE legal_parameter_confirmations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE legal_parameter_confirmations FORCE ROW LEVEL SECURITY;
            ALTER TABLE wage_table_verifications ENABLE ROW LEVEL SECURITY;
            ALTER TABLE wage_table_verifications FORCE ROW LEVEL SECURITY;
            DO $$
            DECLARE t text;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_legal_parameter_publications_touch') THEN
                CREATE TRIGGER trg_legal_parameter_publications_touch BEFORE UPDATE ON legal_parameter_publications FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_legal_parameter_changes_touch') THEN
                CREATE TRIGGER trg_legal_parameter_changes_touch BEFORE UPDATE ON legal_parameter_changes FOR EACH ROW EXECUTE FUNCTION set_updated_at();
              END IF;
              FOREACH t IN ARRAY ARRAY['legal_parameter_confirmations', 'wage_table_verifications'] LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = t AND policyname = 'tenant_isolation') THEN
                  EXECUTE format('CREATE POLICY tenant_isolation ON %I USING (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid)', t);
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_' || t || '_inmutable') THEN
                  EXECUTE format('CREATE TRIGGER %I BEFORE UPDATE OR DELETE ON %I FOR EACH ROW EXECUTE FUNCTION reject_mutation()', 'trg_' || t || '_inmutable', t);
                END IF;
                IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'trg_' || t || '_no_truncate') THEN
                  EXECUTE format('CREATE TRIGGER %I BEFORE TRUNCATE ON %I FOR EACH STATEMENT EXECUTE FUNCTION reject_mutation()', 'trg_' || t || '_no_truncate', t);
                END IF;
              END LOOP;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT ON legal_parameter_confirmations, wage_table_verifications TO gestia_app;
                REVOKE UPDATE, DELETE ON legal_parameter_confirmations, wage_table_verifications FROM gestia_app;
                GRANT SELECT ON legal_parameter_changes TO gestia_app;
                REVOKE INSERT, UPDATE, DELETE ON legal_parameter_changes FROM gestia_app;
                REVOKE ALL ON legal_parameter_publications FROM gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE ON legal_parameter_publications, legal_parameter_changes TO gestia_platform;
                GRANT SELECT, INSERT ON legal_parameter_confirmations, wage_table_verifications TO gestia_platform;
              END IF;
            END $$;
            -- RN-PAR-09 · El plazo de respuesta al teletrabajo se cuenta en días hábiles: su unidad lo dice.
            UPDATE legal_parameters SET unit = 'business_days' WHERE code = 'teletrabajo_respuesta_dias_habiles' AND unit = 'days';
            SQL);

        // Ayuda de la pantalla Parámetros legales (solo crea lo que falta).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void {}
};
