<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ESS-2 · Parámetro colaboradores.campos_editables_portal: qué datos personales puede proponer cambiar el
 * colaborador desde su perfil (los demás solo los cambia RRHH). database/schema.sql lo trae para
 * instalaciones nuevas; aquí se agrega en bases existentes (idempotente).
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
              ('colaboradores.campos_editables_portal', 'core', 'Datos que el colaborador cambia desde el portal', 'ESS-2 · Datos personales que el colaborador puede proponer cambiar desde su perfil; los demás solo los cambia RRHH (con sustento). Los de colaboradores.campos_requieren_aprobacion esperan a RRHH.', 'list', '["preferred_name", "birth_date", "marital_status", "nationality_code", "personal_email", "mobile_phone", "address"]'::jsonb, ARRAY['organization'], '["first_names", "paternal_surname", "maternal_surname", "preferred_name", "birth_date", "sex", "marital_status", "nationality_code", "personal_email", "mobile_phone", "address"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
