<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP-2 · El catálogo de puestos se importa como tipo de datos propio (job_profiles). Idempotente: se
 * rehace la restricción de tipos de import_jobs e import_templates con la lista del esquema.
 */
return new class extends Migration
{
    private const ENTITIES = "'legal_entities','employees','contracts','vacation_balances','salaries','attendance','concepts','history','org_structure','dependents','positions','job_profiles'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['import_jobs', 'import_templates'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_entity_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_entity_check CHECK (entity IN (".self::ENTITIES.'))');
        }
    }

    public function down(): void
    {
        // La restricción ampliada admite todo lo anterior; no se reduce para no invalidar importaciones de puestos.
    }
};
