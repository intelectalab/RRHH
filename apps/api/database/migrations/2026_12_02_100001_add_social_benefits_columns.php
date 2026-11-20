<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BSO-1 a BSO-3 · Depósito de CTS con su proceso, referencia del banco y quién lo registró; distribución de
 * utilidades con la actividad de la empresa, trabajadores contados, avisos, autoría, aprobación y excedente del tope.
 * Los parámetros legales nuevos (parte por días, tope) se leen del paquete de país, no de esta migración.
 *
 * kit/database/schema.sql lo trae para instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE cts_deposits
              ADD COLUMN IF NOT EXISTS payroll_period_id uuid,
              ADD COLUMN IF NOT EXISTS deposit_reference text,
              ADD COLUMN IF NOT EXISTS deposited_by_user_id uuid;
            ALTER TABLE profit_sharing_distributions
              ADD COLUMN IF NOT EXISTS activity text NOT NULL DEFAULT 'otras_actividades',
              ADD COLUMN IF NOT EXISTS workers_count integer,
              ADD COLUMN IF NOT EXISTS calculated_at timestamptz,
              ADD COLUMN IF NOT EXISTS warnings jsonb,
              ADD COLUMN IF NOT EXISTS created_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS approved_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS approved_at timestamptz,
              ADD COLUMN IF NOT EXISTS excess_amount numeric(14,2);
            DO $$
            DECLARE r record;
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'ck_profit_sharing_distributions_activity') THEN
                ALTER TABLE profit_sharing_distributions ADD CONSTRAINT ck_profit_sharing_distributions_activity
                  CHECK (activity IN ('industria','comercio_restaurantes','otras_actividades'));
              END IF;
              FOR r IN SELECT * FROM (VALUES
                ('cts_deposits', 'payroll_period_id', 'payroll_periods'), ('cts_deposits', 'deposited_by_user_id', 'users'),
                ('profit_sharing_distributions', 'created_by_user_id', 'users'), ('profit_sharing_distributions', 'approved_by_user_id', 'users')) AS t(tbl, col, ref)
              LOOP
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_' || r.tbl || '_' || r.col) THEN
                  EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I FOREIGN KEY (tenant_id, %I) REFERENCES %I (tenant_id, id)', r.tbl, 'fk_' || r.tbl || '_' || r.col, r.col, r.ref);
                END IF;
                EXECUTE format('CREATE INDEX IF NOT EXISTS %I ON %I (tenant_id, %I)', 'ix_' || r.tbl || '_' || r.col, r.tbl, r.col);
              END LOOP;
            END $$;
        SQL);
    }

    public function down(): void
    {
        // Columnas aditivas: no se quitan para no perder datos laborales.
    }
};
