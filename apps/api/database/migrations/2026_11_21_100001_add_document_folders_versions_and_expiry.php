<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DOC-1 · DOC-2 · Legajo digital: las carpetas y los tipos documentales (document_categories) definen
 * confidencialidad, visibilidad para el colaborador, revisión y vencimiento con su anticipación de aviso
 * (RN-DOC-09); el documento guarda su responsable, el aviso de vencimiento ya enviado y el archivo; cada versión
 * conserva sus propias fechas de emisión y vencimiento (RN-DOC-01). El parámetro documentos.aviso_vencimiento_dias
 * es la anticipación por defecto. kit/database/schema.sql ya trae este diseño en bases nuevas; aquí se adapta una
 * base existente (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS scope text NOT NULL DEFAULT 'employee';
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS description text;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS requires_expiry boolean NOT NULL DEFAULT false;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS expiry_notice_days smallint;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS visible_to_employee boolean NOT NULL DEFAULT false;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS requires_review boolean NOT NULL DEFAULT false;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS is_system boolean NOT NULL DEFAULT false;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS sort_order smallint NOT NULL DEFAULT 0;
            ALTER TABLE document_categories ADD COLUMN IF NOT EXISTS status text NOT NULL DEFAULT 'active';
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'document_categories_scope_check') THEN
                ALTER TABLE document_categories ADD CONSTRAINT document_categories_scope_check CHECK (scope IN ('employee','company'));
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'document_categories_status_check') THEN
                ALTER TABLE document_categories ADD CONSTRAINT document_categories_status_check CHECK (status IN ('active','archived'));
              END IF;
            END $$;

            ALTER TABLE documents ADD COLUMN IF NOT EXISTS responsible_user_id uuid;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS description text;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS expiry_alerted_on date;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS archived_at timestamptz;
            ALTER TABLE documents ADD COLUMN IF NOT EXISTS archive_reason text;
            DO $$ BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_documents_responsible_user_id') THEN
                ALTER TABLE documents ADD CONSTRAINT fk_documents_responsible_user_id FOREIGN KEY (tenant_id, responsible_user_id) REFERENCES users (tenant_id, id);
              END IF;
            END $$;
            CREATE INDEX IF NOT EXISTS ix_documents_responsible_user_id ON documents (tenant_id, responsible_user_id);
            CREATE INDEX IF NOT EXISTS ix_documents_status_expires_on ON documents (tenant_id, status, expires_on);

            ALTER TABLE document_versions ADD COLUMN IF NOT EXISTS issued_on date;
            ALTER TABLE document_versions ADD COLUMN IF NOT EXISTS expires_on date;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('documentos.aviso_vencimiento_dias', 'core', 'Aviso de vencimiento de documentos', 'DOC-2 · RN-DOC-09: días de anticipación con que se avisa al responsable y al titular que un documento vence, cuando su tipo no fija los suyos.', 'integer', '30'::jsonb, ARRAY['organization','legal_entity'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
