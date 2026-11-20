<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CPT-6 · Las incidencias del periodo se importan como tipo de datos propio (payroll_incidents).
 * CPT-7 · Tabla 22 de la Planilla Electrónica con los códigos cotejados (packages/country-pe/data/tabla22_pe.json),
 * para validar el código de cada concepto con pe_param_ok(22, …). Idempotente: solo agrega lo que falta.
 */
return new class extends Migration
{
    private const ENTITIES = "'legal_entities','employees','contracts','vacation_balances','salaries','attendance','concepts','history','org_structure','dependents','positions','job_profiles','payroll_incidents'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['import_jobs', 'import_templates'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_entity_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_entity_check CHECK (entity IN (".self::ENTITIES.'))');
        }

        $file = base_path('packages/country-pe/data/tabla22_pe.json');
        if (! is_file($file)) {
            return;
        }
        /** @var array{tabla: int, nombre: string, fuente: string, codigos: list<array{codigo: string, descripcion: string}>} $data */
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        DB::statement('INSERT INTO pe_parametric_tables (table_number, name, source_annex) VALUES (?, ?, ?) ON CONFLICT (table_number) DO NOTHING',
            [$data['tabla'], $data['nombre'], 'Anexo 2']);
        foreach ($data['codigos'] as $row) {
            DB::statement('INSERT INTO pe_parametric_items (table_number, code, description, private_sector, source, verified, valid_from)
                SELECT ?, ?, ?, true, ?, true, DATE \'2000-01-01\'
                WHERE NOT EXISTS (SELECT 1 FROM pe_parametric_items WHERE table_number = ? AND code = ?)',
                [$data['tabla'], $row['codigo'], $row['descripcion'], $data['fuente'], $data['tabla'], $row['codigo']]);
        }
    }

    public function down(): void
    {
        // Datos de catálogo: no se retiran.
    }
};
