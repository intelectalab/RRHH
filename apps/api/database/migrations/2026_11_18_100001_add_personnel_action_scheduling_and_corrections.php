<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HIS-2 · HIS-4 · Movimientos de personal con aprobación, programados a futuro y corregidos:
 * - personnel_actions suma los estados «programado» (scheduled) y «reemplazado» (replaced), los cambios
 *   pedidos (changes), la decisión, la anulación, el enlace corrección ↔ original y el rango retroactivo.
 * - Parámetro movimientos.tipos_con_aprobacion.
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
            ALTER TABLE personnel_actions
              ADD COLUMN IF NOT EXISTS changes jsonb,
              ADD COLUMN IF NOT EXISTS decided_at timestamptz,
              ADD COLUMN IF NOT EXISTS decision_reason text,
              ADD COLUMN IF NOT EXISTS cancelled_at timestamptz,
              ADD COLUMN IF NOT EXISTS cancelled_by_user_id uuid,
              ADD COLUMN IF NOT EXISTS replaces_personnel_action_id uuid,
              ADD COLUMN IF NOT EXISTS replaced_by_personnel_action_id uuid,
              ADD COLUMN IF NOT EXISTS is_retroactive boolean NOT NULL DEFAULT false,
              ADD COLUMN IF NOT EXISTS affected_from date,
              ADD COLUMN IF NOT EXISTS affected_to date;

            ALTER TABLE personnel_actions DROP CONSTRAINT IF EXISTS personnel_actions_status_check;
            ALTER TABLE personnel_actions ADD CONSTRAINT personnel_actions_status_check
              CHECK (status IN ('draft','pending_approval','approved','scheduled','applied','rejected','cancelled','replaced'));

            DO $$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_personnel_actions_cancelled_by_user_id') THEN
                ALTER TABLE personnel_actions ADD CONSTRAINT fk_personnel_actions_cancelled_by_user_id
                  FOREIGN KEY (tenant_id, cancelled_by_user_id) REFERENCES users (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_personnel_actions_replaces_personnel_action_id') THEN
                ALTER TABLE personnel_actions ADD CONSTRAINT fk_personnel_actions_replaces_personnel_action_id
                  FOREIGN KEY (tenant_id, replaces_personnel_action_id) REFERENCES personnel_actions (tenant_id, id);
              END IF;
              IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_personnel_actions_replaced_by_personnel_action_id') THEN
                ALTER TABLE personnel_actions ADD CONSTRAINT fk_personnel_actions_replaced_by_personnel_action_id
                  FOREIGN KEY (tenant_id, replaced_by_personnel_action_id) REFERENCES personnel_actions (tenant_id, id);
              END IF;
            END
            $$;

            CREATE INDEX IF NOT EXISTS ix_personnel_actions_cancelled_by_user_id ON personnel_actions (tenant_id, cancelled_by_user_id);
            CREATE INDEX IF NOT EXISTS ix_personnel_actions_replaces_personnel_action_id ON personnel_actions (tenant_id, replaces_personnel_action_id);
            CREATE INDEX IF NOT EXISTS ix_personnel_actions_replaced_by_personnel_action_id ON personnel_actions (tenant_id, replaced_by_personnel_action_id);
            CREATE INDEX IF NOT EXISTS ix_personnel_actions_status_effective_on ON personnel_actions (tenant_id, status, effective_on);

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('movimientos.tipos_con_aprobacion', 'core', 'Movimientos que pasan por aprobación', 'HIS-2: tipos de movimiento de personal que, aunque los registre RRHH, esperan el flujo de aprobación publicado para «personnel_action» antes de aplicarse. Lo que propone un jefe siempre pasa por aprobación.', 'list', '["promotion"]'::jsonb, ARRAY['organization','legal_entity'], '["promotion", "transfer", "manager_change", "cost_center_change", "data_correction"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
