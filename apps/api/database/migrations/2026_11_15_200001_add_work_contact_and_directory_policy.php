<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ORG-6 · ORG-7 · Directorio y organigrama.
 *  - Contacto laboral de la relación laboral (work_email, work_phone, work_phone_ext): lo único de
 *    contacto que el directorio puede publicar (RN-ORG-08); el correo y el celular personales no salen.
 *  - Parámetro directorio.campos_contacto: la organización decide qué datos de contacto ven todos.
 *  - people.directory.view vale para toda la organización en los roles de sistema que lo tienen: el
 *    directorio limita campos, no personas (Empleado y Manager lo tenían con alcance propio o de equipo).
 * database/schema.sql los trae para instalaciones nuevas; aquí se agregan en bases existentes (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE employments ADD COLUMN IF NOT EXISTS work_email text;
            ALTER TABLE employments ADD COLUMN IF NOT EXISTS work_phone text;
            ALTER TABLE employments ADD COLUMN IF NOT EXISTS work_phone_ext text;

            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('directorio.campos_contacto', 'core', 'Contacto visible en el directorio', 'Datos de contacto laboral que todos ven en el directorio y el organigrama (RN-ORG-08). Nunca incluye correo ni celular personal ni datos sensibles.', 'list', '["work_email", "work_phone"]'::jsonb, ARRAY['organization'], '["work_email", "work_phone", "work_phone_ext"]'::jsonb)
            ON CONFLICT (code) DO NOTHING;

            UPDATE system_role_permissions SET scope = 'organization'
             WHERE permission_code = 'people.directory.view' AND scope IN ('own', 'team');
            UPDATE role_permissions rp SET scope = 'organization'
              FROM roles r
             WHERE r.id = rp.role_id AND r.tenant_id = rp.tenant_id AND r.system_role_code IN ('empleado', 'manager')
               AND rp.permission_code = 'people.directory.view' AND rp.scope IN ('own', 'team');
            SQL);
    }

    public function down(): void {}
};
