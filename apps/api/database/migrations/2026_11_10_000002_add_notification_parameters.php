<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * NOT-1 · NOT-6 · Parámetros de notificaciones (horario de silencio, avisos obligatorios, retención).
 * database/schema.sql ya los trae para instalaciones nuevas; aquí se agregan en bases existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
                ('notificaciones.silencio_desde', 'base', 'Inicio del horario de silencio', 'Hora local desde la que los avisos no urgentes por canal externo se difieren (RN-NOT-03). Vacío: sin silencio.', 'time', NULL, ARRAY['organization'], NULL),
                ('notificaciones.silencio_hasta', 'base', 'Fin del horario de silencio', 'Hora local en que se entregan los avisos diferidos por el silencio (RN-NOT-03).', 'time', NULL, ARRAY['organization'], NULL),
                ('notificaciones.obligatorias', 'base', 'Avisos obligatorios', 'Tipos de aviso que el usuario no puede desactivar en la aplicación, además de los del producto (RN-NOT-05).', 'list', '[]'::jsonb, ARRAY['organization'], NULL),
                ('notificaciones.retencion_dias', 'base', 'Retención de avisos leídos (días)', 'Días que un aviso leído se muestra en el centro de notificaciones antes de archivarse.', 'integer', '180'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        // Catálogo de parámetros: no se eliminan definiciones con posibles valores guardados.
    }
};
