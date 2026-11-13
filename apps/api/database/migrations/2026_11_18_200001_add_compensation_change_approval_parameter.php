<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HIS-3 · Parámetro compensacion.cambio_requiere_aprobacion: un cambio de sueldo pasa por el flujo de
 * aprobación de salary_change antes de registrarse. database/schema.sql lo trae para instalaciones nuevas;
 * aquí se agrega en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('compensacion.cambio_requiere_aprobacion', 'core', 'Cambios de sueldo con aprobación', 'HIS-3 · Un cambio de sueldo o de componentes fijos pasa por el flujo de aprobación publicado para salary_change antes de registrarse; con falso se registra en el acto (siempre con motivo y auditoría).', 'boolean', 'true'::jsonb, ARRAY['organization','legal_entity'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
