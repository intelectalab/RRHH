<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MSS-4 · Fechas clave del equipo: parámetros para ocultar los cumpleaños en la organización y para la
 * anticipación del recordatorio de cumpleaños y aniversarios al jefe.
 *
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
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('celebraciones.mostrar_cumpleanos', 'core', 'Mostrar cumpleaños', 'MSS-4 · RN-CIN-04: con «no», los cumpleaños no aparecen en ningún tablero, en las fechas clave del equipo ni en los recordatorios. Cuando se muestran, solo viaja el día (nunca el año ni la edad).', 'boolean', 'true'::jsonb, ARRAY['organization'], NULL),
              ('equipo.aviso_fechas_clave_dias', 'core', 'Recordatorio de cumpleaños y aniversarios (días)', 'MSS-4: días antes de un cumpleaños o aniversario de ingreso en que se avisa al jefe. 0: el mismo día. El fin del periodo de prueba y el vencimiento de contrato se avisan con sus propios parámetros.', 'integer', '1'::jsonb, ARRAY['organization'], '{"min": 0, "max": 30}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
