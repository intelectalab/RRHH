<?php

/**
 * HIS-6 · C-11 · Prueba de arquitectura: ningún módulo guarda una copia editable del sueldo o del jefe vigentes.
 * Se leen con App\Shared\Labor\MasterData. Las únicas columnas permitidas con esos nombres son las fuentes
 * (asignación y compensación) y datos de otra naturaleza que no son el sueldo ni el jefe vigentes; cada
 * excepción lleva su razón. Una tabla nueva con una de estas columnas hace fallar la prueba hasta que se
 * justifique aquí o se lea del servicio.
 */

use Illuminate\Support\Facades\DB;

/** Nombres de columna que delatan una copia del sueldo o del jefe. */
const MASTER_DATA_SUSPECT_COLUMNS = [
    'base_salary', 'current_salary', 'monthly_salary', 'salary', 'salary_amount', 'gross_salary',
    'manager_employment_id', 'manager_person_id', 'manager_user_id', 'boss_employment_id',
    'supervisor_employment_id', 'reports_to_employment_id', 'direct_manager_id',
];

/** tabla.columna => por qué no es una copia editable del dato maestro. */
const MASTER_DATA_ALLOWED = [
    'employment_assignments.manager_employment_id' => 'Fuente: jefe por relación de la asignación vigente (HIS-2).',
    'employment_compensations.base_salary' => 'Fuente: sueldo con vigencia (HIS-3).',
    'projects.manager_employment_id' => 'Responsable del proyecto, no la línea de reporte.',
    'job_offers.base_salary' => 'Oferta a un candidato antes de que exista la relación laboral.',
    'performance_cycle_participants.manager_employment_id' => 'Evaluador fijado al abrir el ciclo (fotografía de solo lectura del ciclo).',
    'one_on_one_series.manager_employment_id' => 'Participante de la serie de reuniones 1:1.',
    'compensation_review_budgets.manager_user_id' => 'Responsable del presupuesto de revisión salarial.',
    'compensation_review_proposals.current_salary' => 'Fotografía inmutable del sueldo al proponer el incremento.',
];

test('HIS-6 · ninguna tabla fuera de las fuentes duplica el sueldo o el jefe como dato editable', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    $found = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereIn('column_name', MASTER_DATA_SUSPECT_COLUMNS)
        ->whereNotIn('table_name', fn ($q) => $q->select('table_name')->from('information_schema.views')->where('table_schema', 'public'))
        ->orderBy('table_name')->get(['table_name', 'column_name'])
        ->map(fn ($c): string => $c->table_name.'.'.$c->column_name)->all();

    $unexpected = array_values(array_diff($found, array_keys(MASTER_DATA_ALLOWED)));
    expect($unexpected)->toBe([], 'Columnas que copian el sueldo o el jefe: léelas de App\Shared\Labor\MasterData o justifícalas en MASTER_DATA_ALLOWED: '.implode(', ', $unexpected))
        // Las fuentes siguen existiendo: si cambian de nombre, la lista se revisa.
        ->and($found)->toContain('employment_assignments.manager_employment_id')
        ->and($found)->toContain('employment_compensations.base_salary');
});

test('HIS-6 · los módulos leen el jefe y el sueldo vigentes del servicio, no de las tablas fuente', function (): void {
    // Fuera de Core HR (dueño de las fuentes), los módulos de dominio no consultan employment_compensations: el
    // sueldo vigente se pide a MasterData. La lista de excepciones conocidas queda explícita.
    $allowed = ['CoreHR'];
    // Archivos que escriben la fuente con vigencia (cerrar e insertar), no que la copian.
    $writers = ['Contracts/Application/AmendmentService.php' => 'CON-3 · RN-CON-08: la adenda de sueldo firmada inserta la nueva fila de compensación.'];
    $offenders = [];
    $base = dirname(__DIR__, 3).'/app/Modules';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $module = explode('/', substr($file->getPathname(), strlen($base) + 1))[0];
        if (in_array($module, $allowed, true) || array_key_exists(substr($file->getPathname(), strlen($base) + 1), $writers)) {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), "'employment_compensations'")) {
            $offenders[] = substr($file->getPathname(), strlen($base) + 1);
        }
    }
    expect($offenders)->toBe([], 'Lee el sueldo con App\Shared\Labor\MasterData: '.implode(', ', $offenders));
});
