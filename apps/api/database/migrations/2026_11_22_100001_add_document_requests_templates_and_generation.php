<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DOC-3 · DOC-4 · DOC-5 · Solicitudes de documentos (agrupadas por envío, con entrega, revisión y cancelación),
 * plantillas por tipo documental y empresa con versiones (borrador sin published_at; autor y notas) y lotes de
 * generación de PDF en segundo plano con su avance y el resultado por persona. kit/database/schema.sql ya trae
 * este diseño en bases nuevas; aquí se adapta una base existente (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS request_group_id uuid;
            ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS title text NOT NULL DEFAULT '';
            ALTER TABLE document_requests ALTER COLUMN title DROP DEFAULT;
            ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
            ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS reviewed_by_user_id uuid;
            ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS reviewed_at timestamptz;
            ALTER TABLE document_requests ADD COLUMN IF NOT EXISTS cancellation_reason text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_document_requests_reviewed_by_user_id') THEN
                ALTER TABLE document_requests ADD CONSTRAINT fk_document_requests_reviewed_by_user_id FOREIGN KEY (tenant_id, reviewed_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_document_requests_reviewed_by_user_id ON document_requests (tenant_id, reviewed_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_document_requests_status_due_on ON document_requests (tenant_id, status, due_on);

            ALTER TABLE document_templates ADD COLUMN IF NOT EXISTS legal_entity_id uuid;
            ALTER TABLE document_templates ADD COLUMN IF NOT EXISTS description text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_document_templates_legal_entity_id') THEN
                ALTER TABLE document_templates ADD CONSTRAINT fk_document_templates_legal_entity_id FOREIGN KEY (tenant_id, legal_entity_id) REFERENCES legal_entities (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_document_templates_legal_entity_id ON document_templates (tenant_id, legal_entity_id);

            ALTER TABLE document_template_versions ADD COLUMN IF NOT EXISTS created_by_user_id uuid;
            ALTER TABLE document_template_versions ADD COLUMN IF NOT EXISTS notes text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_document_template_versions_created_by_user_id') THEN
                ALTER TABLE document_template_versions ADD CONSTRAINT fk_document_template_versions_created_by_user_id FOREIGN KEY (tenant_id, created_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_document_template_versions_created_by_user_id ON document_template_versions (tenant_id, created_by_user_id);

            CREATE TABLE IF NOT EXISTS document_generation_batches (
              id uuid PRIMARY KEY DEFAULT uuid_v7(),
              tenant_id uuid NOT NULL REFERENCES tenants (id),
              document_template_id uuid NOT NULL,
              document_template_version_id uuid NOT NULL,
              requested_by_user_id uuid,
              status text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued','running','completed','completed_with_errors','failed')),
              total integer NOT NULL DEFAULT 0,
              generated integer NOT NULL DEFAULT 0,
              failed integer NOT NULL DEFAULT 0,
              items jsonb,
              started_at timestamptz,
              finished_at timestamptz,
              created_at timestamptz NOT NULL DEFAULT now(),
              updated_at timestamptz NOT NULL DEFAULT now(),
              lock_version integer NOT NULL DEFAULT 1,
              CONSTRAINT uq_document_generation_batches_tenant_id UNIQUE (tenant_id, id),
              CONSTRAINT fk_document_generation_batches_document_template_id FOREIGN KEY (tenant_id, document_template_id) REFERENCES document_templates (tenant_id, id),
              CONSTRAINT fk_document_generation_batches_document_template_version_id FOREIGN KEY (tenant_id, document_template_version_id) REFERENCES document_template_versions (tenant_id, id),
              CONSTRAINT fk_document_generation_batches_requested_by_user_id FOREIGN KEY (tenant_id, requested_by_user_id) REFERENCES users (tenant_id, id)
            );
            COMMENT ON TABLE document_generation_batches IS 'DOC-5: generación de documentos PDF desde una plantilla para una o varias relaciones laborales, en segundo plano: avance (generados y con error) y el resultado por persona en items, con el motivo de los omitidos.';
            CREATE INDEX IF NOT EXISTS ix_document_generation_batches_document_template_id ON document_generation_batches (tenant_id, document_template_id);
            CREATE INDEX IF NOT EXISTS ix_document_generation_batches_document_template_version_id ON document_generation_batches (tenant_id, document_template_version_id);
            CREATE INDEX IF NOT EXISTS ix_document_generation_batches_requested_by_user_id ON document_generation_batches (tenant_id, requested_by_user_id);
            ALTER TABLE document_generation_batches ENABLE ROW LEVEL SECURITY;
            ALTER TABLE document_generation_batches FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON document_generation_batches;
            CREATE POLICY tenant_isolation ON document_generation_batches USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
            DROP TRIGGER IF EXISTS trg_document_generation_batches_touch ON document_generation_batches;
            CREATE TRIGGER trg_document_generation_batches_touch BEFORE UPDATE ON document_generation_batches FOR EACH ROW EXECUTE FUNCTION set_updated_at_and_lock();
            DO $$ BEGIN
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_app') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON document_generation_batches TO gestia_app;
              END IF;
              IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'gestia_platform') THEN
                GRANT SELECT, INSERT, UPDATE, DELETE ON document_generation_batches TO gestia_platform;
              END IF;
            END $$;
            SQL);
    }

    public function down(): void
    {
        // Sin vuelta atrás: guarda solicitudes, plantillas y lotes reales.
    }
};
