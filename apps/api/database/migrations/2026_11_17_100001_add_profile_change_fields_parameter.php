<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COL-4 · RN-COL-06 · Parámetro colaboradores.campos_requieren_aprobacion: qué datos personales,
 * propuestos por el propio colaborador, esperan a RRHH (los demás se aplican en el acto).
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
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('colaboradores.campos_requieren_aprobacion', 'core', 'Datos que el colaborador cambia con aprobación', 'RN-COL-06 · Datos personales que, propuestos por el propio colaborador, esperan a RRHH; los demás (nombre preferido, correo y celular personales) se aplican en el acto.', 'list', '["first_names", "paternal_surname", "maternal_surname", "birth_date", "sex", "marital_status", "nationality_code", "address"]'::jsonb, ARRAY['organization'], '["first_names", "paternal_surname", "maternal_surname", "preferred_name", "birth_date", "sex", "marital_status", "nationality_code", "personal_email", "mobile_phone", "address"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
