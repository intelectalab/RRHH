<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEG-12 · Marca de agua opcional en exportaciones (nombre de quien descarga y fecha).
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
              ('seguridad.marca_agua_descargas', 'base', 'Marca de agua en exportaciones', 'Agrega a cada archivo exportado el nombre de quien lo descargó y la fecha.', 'boolean', 'false'::jsonb, ARRAY['organization'], NULL)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
