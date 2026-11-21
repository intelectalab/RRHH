<?php

use App\Modules\Platform\Application\Help\ProductHelpArticles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DEC-7 a DEC-11 · Bitácora de declaraciones (responsable, pago y constancia de pago), validación y mes de aplicación
 * de la declaración jurada de rentas de otros empleadores, y segregación de funciones en las devoluciones de quinta
 * (quien calcula no aprueba, RN-PE-QTA-18) con el detalle de sus compensaciones, y la ayuda de las pantallas nuevas.
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
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS responsible_user_id uuid;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS paid_at timestamptz;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS paid_by_user_id uuid;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS payment_reference text;
            ALTER TABLE statutory_filings ADD COLUMN IF NOT EXISTS payment_document_id uuid;

            ALTER TABLE external_income_declarations ADD COLUMN IF NOT EXISTS validated_on date;
            ALTER TABLE external_income_declarations ADD COLUMN IF NOT EXISTS validated_by_user_id uuid;
            ALTER TABLE external_income_declarations ADD COLUMN IF NOT EXISTS applies_from_month smallint;
            ALTER TABLE external_income_declarations ADD COLUMN IF NOT EXISTS rejection_reason text;
            ALTER TABLE external_income_declarations ADD COLUMN IF NOT EXISTS submitted_by_user_id uuid;

            ALTER TABLE fifth_category_refunds ADD COLUMN IF NOT EXISTS calculated_by_user_id uuid;
            ALTER TABLE fifth_category_refunds ADD COLUMN IF NOT EXISTS refunded_by_user_id uuid;
            ALTER TABLE fifth_category_refunds ADD COLUMN IF NOT EXISTS compensations jsonb;

            DO $$
            DECLARE
              r record;
            BEGIN
              FOR r IN SELECT * FROM (VALUES
                ('statutory_filings', 'responsible_user_id', 'users'),
                ('statutory_filings', 'paid_by_user_id', 'users'),
                ('statutory_filings', 'payment_document_id', 'documents'),
                ('external_income_declarations', 'validated_by_user_id', 'users'),
                ('external_income_declarations', 'submitted_by_user_id', 'users'),
                ('fifth_category_refunds', 'calculated_by_user_id', 'users'),
                ('fifth_category_refunds', 'refunded_by_user_id', 'users')
              ) AS t(tbl, col, ref) LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_' || r.tbl || '_' || r.col) THEN
                  EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (tenant_id, %I) REFERENCES %I (tenant_id, id)',
                    r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col, r.ref);
                END IF;
                EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (tenant_id, %I)', 'ix_' || r.tbl || '_' || r.col, r.tbl, r.col);
              END LOOP;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_external_income_declarations_3') THEN
                ALTER TABLE external_income_declarations ADD CONSTRAINT ck_external_income_declarations_3
                  CHECK (applies_from_month IS NULL OR applies_from_month BETWEEN 1 AND 12);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_fifth_category_refunds_3') THEN
                ALTER TABLE fifth_category_refunds ADD CONSTRAINT ck_fifth_category_refunds_3
                  CHECK (refunded_by_user_id IS NULL OR calculated_by_user_id IS NULL OR refunded_by_user_id <> calculated_by_user_id);
              END IF;
            END $$;
            SQL);
        // Ayuda de las pantallas nuevas (bitácora, registro laboral, devoluciones de quinta y rentas de otros empleadores).
        if (Schema::hasTable('help_articles') && Schema::hasTable('help_article_versions')) {
            app(ProductHelpArticles::class)->syncBundled();
        }
    }

    public function down(): void
    {
        // Las columnas se conservan: retirarlas perdería la evidencia de pago, de validación y de segregación.
    }
};
