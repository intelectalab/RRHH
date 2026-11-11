<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CON-1 · Tipos de contrato: parámetro del periodo de prueba, «exige fecha de fin», marca de tipo
 * del paquete de país y permisos para ver y crear tipos propios.
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
            ALTER TABLE contract_types ADD COLUMN IF NOT EXISTS trial_period_parameter_code text;
            ALTER TABLE contract_types ADD COLUMN IF NOT EXISTS requires_end_date boolean NOT NULL DEFAULT false;
            ALTER TABLE contract_types ADD COLUMN IF NOT EXISTS is_system boolean NOT NULL DEFAULT false;

            INSERT INTO permissions (code, module_code, resource, action, kind, is_sensitive, description) VALUES
              ('contracts.contract_types.view', 'core', 'contract_types', 'view', 'action', false, 'Ver tipos de contrato y sus topes'),
              ('contracts.contract_types.create', 'core', 'contract_types', 'create', 'action', false, 'Crear tipos de contrato propios de la organización')
            ON CONFLICT (code) DO NOTHING;
            UPDATE permissions SET description = 'Administrar tipos de contrato y sus topes' WHERE code = 'contracts.contract_types.update';

            INSERT INTO system_role_permissions (role_code, permission_code, scope) VALUES
              ('admin_empresa', 'contracts.contract_types.view', 'organization'),
              ('admin_empresa', 'contracts.contract_types.create', 'organization'),
              ('rrhh', 'contracts.contract_types.view', 'organization'),
              ('payroll', 'contracts.contract_types.view', 'organization'),
              ('auditor', 'contracts.contract_types.view', 'organization')
            ON CONFLICT DO NOTHING;

            -- Organizaciones existentes: los roles de sistema reciben los permisos nuevos.
            INSERT INTO role_permissions (tenant_id, role_id, permission_code, scope)
            SELECT r.tenant_id, r.id, srp.permission_code, srp.scope
              FROM roles r
              JOIN system_role_permissions srp ON srp.role_code = r.system_role_code
             WHERE srp.permission_code IN ('contracts.contract_types.view', 'contracts.contract_types.create')
               AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id AND rp.permission_code = srp.permission_code);
            SQL);
    }

    public function down(): void {}
};
