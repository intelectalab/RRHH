<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * COL-10 · COL-11 · Parámetros del cese (RN-COL-10: pendientes que bloquean o solo advierten) y de las
 * relaciones simultáneas en empresas del grupo. Los motivos de cese de cada organización se cargan desde
 * la Tabla 17 del paquete de país la primera vez que se piden; el preaviso de renuncia es un parámetro
 * legal (gestia:legal-parameters:sync).
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
              ('colaboradores.cese_bloquea_pendientes', 'core', 'Bloquear el cese con pendientes', 'Si el cese se registra con activos sin devolver, préstamos o adelantos con saldo o vacaciones aprobadas posteriores a la fecha de cese, se rechaza en lugar de solo advertir (RN-COL-10).', 'boolean', 'false'::jsonb, ARRAY['organization','legal_entity'], NULL),
              ('colaboradores.relaciones_simultaneas', 'core', 'Relaciones simultáneas en empresas del grupo', 'Permite que una persona tenga a la vez relaciones laborales abiertas con distintas empresas de la organización (COL-10). Con la misma empresa nunca se permite (RN-COL-02).', 'boolean', 'true'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
