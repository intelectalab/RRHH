<?php

/**
 * IMP-1 · Motor de importación: subir → mapear → validar (vista previa) → confirmar → lotes → deshacer.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Modules\Imports\Domain\ImportJobStateMachine;
use App\Modules\Imports\Infrastructure\Models\ImportJob;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Imports\ImportColumn;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\Importer;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Imports\ImportValues;
use App\Shared\Imports\RowCheck;
use App\Shared\Imports\SpreadsheetReader;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const IMP_TENANT = 'aaaaaaaa-1a71-4000-8000-00000000000a';
const IMP_OTHER = 'bbbbbbbb-1a71-4000-8000-00000000000b';
const IMP_CAN = ['imports.imports.view', 'imports.imports.create', 'imports.imports.map', 'imports.imports.validate',
    'imports.imports.execute', 'imports.imports.cancel', 'imports.imports.revert', 'org.legal_entities.create', 'org.legal_entities.view'];

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => IMP_TENANT, 'slug' => 'imp-org-a', 'name' => 'Imp A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => IMP_OTHER, 'slug' => 'imp-org-b', 'name' => 'Imp B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [IMP_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [IMP_OTHER]);
    $this->user = IdentityFixtures::userWithPermissions(IMP_TENANT, IMP_CAN);
});

afterEach(function (): void {
    TenancyScope::clear();
});

function impCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $req = test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id]);

    return $method === 'get' ? $req->get('/api/v1'.$path, ['Accept' => 'application/json']) : $req->postJson('/api/v1'.$path, $body);
}

/** CSV como lo guarda Excel en español: punto y coma y Windows-1252. */
function impCsv(array $lines, string $sep = ';', bool $latin = true): string
{
    $text = implode("\r\n", array_map(fn ($cells) => implode($sep, $cells), $lines))."\r\n";

    return $latin ? (string) iconv('UTF-8', 'Windows-1252', $text) : $text;
}

function impUpload(User $user, string $contents, string $name = 'empresas.csv', string $entity = 'legal_entities')
{
    return impCall($user, 'post', '/imports', ['entity' => $entity, 'file_name' => $name, 'content_base64' => base64_encode($contents)]);
}

function impRun(string $jobId): void
{
    TenancyScope::runAs(IMP_TENANT, fn () => app(ImportRunner::class)->run($jobId));
}

function impSetParameter(string $code, mixed $value): void
{
    TenancyScope::runAs(IMP_TENANT, fn () => DB::table('tenant_settings')->insert([
        'tenant_id' => IMP_TENANT, 'parameter_code' => $code, 'scope_kind' => 'organization', 'value' => json_encode($value), 'valid_from' => now()->subDay()->toDateString(),
    ]));
}

/** Archivo con dos empresas válidas, una con RUC inválido y una que repite la primera. */
function impSampleCsv(): string
{
    return impCsv([
        ['Razón social', 'RUC', 'Nombre comercial', 'Régimen'],
        ['Andina Servicios S.A.C.', '20123456781', 'Andina', 'Régimen general'],
        ['Pacífico Norte S.A.', '20987654321', '', 'pe_micro'],
        ['Mal RUC S.A.C.', '123', '', 'pe_general'],
        ['Andina Repetida S.A.C.', '20123456781', '', 'pe_general'],
    ]);
}

test('flujo completo: subir, mapear, validar con vista previa, descargar errores, confirmar e importar por lotes', function (): void {
    $types = impCall($this->user, 'get', '/import-types');
    $this->assertMatchesOpenApi($types, 'get', '/import-types', 200);
    $type = collect($types->json('data'))->firstWhere('entity', 'legal_entities');
    expect($type['label'])->toBe('Empresas')
        ->and(collect($type['columns'])->where('required', true)->pluck('key')->all())->toBe(['name', 'tax_id', 'payroll_regime']);

    $up = impUpload($this->user, impSampleCsv());
    $this->assertMatchesOpenApi($up, 'post', '/imports', 201);
    $id = (string) $up->json('data.id');
    expect($up->json('data.status'))->toBe('uploaded')
        ->and($up->json('data.total_rows'))->toBe(4)
        ->and($up->json('data.headers'))->toBe(['Razón social', 'RUC', 'Nombre comercial', 'Régimen'])
        ->and($up->json('data.summary.delimiter'))->toBe(';')
        ->and($up->json('data.allowed_actions'))->toBe(['map', 'cancel'])
        // Mapeo propuesto por nombre de cabecera (etiquetas y alias, sin tildes).
        ->and($up->json('data.suggested_mapping'))->toBe(['name' => 'Razón social', 'tax_id' => 'RUC', 'trade_name' => 'Nombre comercial', 'payroll_regime' => 'Régimen']);

    // RN-IMP-01: no se puede importar sin validar.
    $early = impCall($this->user, 'post', "/imports/{$id}/execute", ['confirm' => true]);
    $this->assertMatchesOpenApi($early, 'post', '/imports/{id}/execute', 409);
    expect($early->json('error.code'))->toBe('invalid_transition');

    $mapped = impCall($this->user, 'post', "/imports/{$id}/map", ['mapping' => $up->json('data.suggested_mapping'), 'save_as' => 'Empresas estándar']);
    $this->assertMatchesOpenApi($mapped, 'post', '/imports/{id}/map', 200);
    expect($mapped->json('data.status'))->toBe('mapped');

    $validated = impCall($this->user, 'post', "/imports/{$id}/validate");
    $this->assertMatchesOpenApi($validated, 'post', '/imports/{id}/validate', 200);
    expect($validated->json('data.status'))->toBe('validated')
        ->and($validated->json('data.valid_rows'))->toBe(2)
        ->and($validated->json('data.error_rows'))->toBe(2);
    // Validar no escribe en el módulo dueño.
    expect(TenancyScope::runAs(IMP_TENANT, fn () => LegalEntity::query()->count()))->toBe(0);

    $preview = impCall($this->user, 'get', "/imports/{$id}/rows?filter[status]=invalid");
    $this->assertMatchesOpenApi($preview, 'get', '/imports/{id}/rows', 200);
    expect($preview->json('meta.total'))->toBe(2)
        ->and($preview->json('meta.counts'))->toBe(['invalid' => 2, 'valid' => 2])
        ->and($preview->json('data.0.row_number'))->toBe(4)
        ->and($preview->json('data.0.errors.0.column'))->toBe('tax_id')
        ->and($preview->json('data.1.errors.0.code'))->toBe('duplicate_in_file');

    $csv = impCall($this->user, 'get', "/imports/{$id}/errors/download");
    $this->assertMatchesOpenApi($csv, 'get', '/imports/{id}/errors/download', 200);
    $lines = array_map(fn ($l) => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", ltrim((string) $csv->getContent(), "\xEF\xBB\xBF"))));
    expect($lines[0])->toBe(['Fila', 'Razón social', 'RUC', 'Nombre comercial', 'Régimen', 'Motivo'])
        ->and(count($lines))->toBe(3)
        ->and($lines[1][0])->toBe('4')
        ->and($lines[1][5])->toContain('RUC')
        ->and($lines[2][5])->toContain('fila 2');

    // Confirmar exige la vista previa revisada.
    $noConfirm = impCall($this->user, 'post', "/imports/{$id}/execute", []);
    $this->assertMatchesOpenApi($noConfirm, 'post', '/imports/{id}/execute', 422);

    $exec = impCall($this->user, 'post', "/imports/{$id}/execute", ['confirm' => true]);
    $this->assertMatchesOpenApi($exec, 'post', '/imports/{id}/execute', 200);
    expect($exec->json('data.status'))->toBe('importing')
        ->and($exec->json('data.batch_size'))->toBe(500);

    impRun($id);
    impRun($id); // repetir el evento no duplica nada

    $done = impCall($this->user, 'get', "/imports/{$id}");
    $this->assertMatchesOpenApi($done, 'get', '/imports/{id}', 200);
    expect($done->json('data.status'))->toBe('completed')
        ->and($done->json('data.imported_rows'))->toBe(2)
        ->and($done->json('data.allowed_actions'))->toBe(['revert']);

    TenancyScope::runAs(IMP_TENANT, function () use ($id): void {
        $andina = LegalEntity::query()->where('tax_id', '20123456781')->firstOrFail();
        expect($andina->legal_name)->toBe('Andina Servicios S.A.C.')
            ->and($andina->trade_name)->toBe('Andina')
            ->and($andina->currency)->toBe('PEN')
            ->and($andina->timezone)->toBe('America/Lima')
            ->and($andina->currentRegime()?->labor_regime_code)->toBe('pe_general')
            ->and(LegalEntity::query()->count())->toBe(2);
        expect(DB::table('import_job_rows')->where('import_job_id', $id)->pluck('status', 'row_number')->all())
            ->toBe([2 => 'imported', 3 => 'imported', 4 => 'skipped', 5 => 'skipped']);
        // Mismas reglas y efectos que el alta manual: auditoría y evento company.created.
        expect(DB::table('audit_events')->where('action', 'legal_entity.created')->count())->toBe(2)
            ->and(DB::table('audit_events')->where('entity_type', 'import_job')->where('entity_id', $id)->pluck('action')->all())
            ->toContain('import.created', 'import.mapped', 'import.validated', 'import.started', 'import.completed', 'import.errors_downloaded')
            ->and(DB::table('outbox_events')->where('aggregate_id', $id)->pluck('event_type')->all())
            ->toContain('import.validated.v1', 'import.started.v1', 'import.completed.v1')
            // El mapeo queda guardado para la próxima importación.
            ->and(DB::table('import_templates')->where('name', 'Empresas estándar')->value('column_mapping'))->toContain('Razón social');
    });

    $list = impCall($this->user, 'get', '/imports');
    $this->assertMatchesOpenApi($list, 'get', '/imports', 200);
    expect($list->json('data.0.id'))->toBe($id)
        ->and($list->json('data.0.created_by_name'))->not->toBeNull();
});

test('RN-IMP-02: con la política «corregir todo» un archivo con errores no se puede importar', function (): void {
    impSetParameter('importacion.politica_errores', 'fix_all');
    $id = (string) impUpload($this->user, impSampleCsv())->json('data.id');
    impCall($this->user, 'post', "/imports/{$id}/map", ['mapping' => ['name' => 'Razón social', 'tax_id' => 'RUC', 'payroll_regime' => 'Régimen']])->assertOk();

    $validated = impCall($this->user, 'post', "/imports/{$id}/validate");
    expect($validated->json('data.status'))->toBe('with_errors')
        ->and($validated->json('data.allowed_actions'))->toBe(['validate', 'cancel']);

    $exec = impCall($this->user, 'post', "/imports/{$id}/execute", ['confirm' => true]);
    $this->assertMatchesOpenApi($exec, 'post', '/imports/{id}/execute', 409);

    $cancel = impCall($this->user, 'post', "/imports/{$id}/cancel");
    $this->assertMatchesOpenApi($cancel, 'post', '/imports/{id}/cancel', 200);
    expect($cancel->json('data.status'))->toBe('cancelled')
        ->and($cancel->json('data.file_id'))->toBeNull()
        ->and(TenancyScope::runAs(IMP_TENANT, fn () => DB::table('import_job_rows')->where('import_job_id', $id)->count()))->toBe(0);
});

test('mapeo: columnas obligatorias, cabeceras inexistentes y la misma cabecera dos veces dan 422', function (): void {
    $id = (string) impUpload($this->user, impSampleCsv())->json('data.id');

    $missing = impCall($this->user, 'post', "/imports/{$id}/map", ['mapping' => ['name' => 'Razón social']]);
    $this->assertMatchesOpenApi($missing, 'post', '/imports/{id}/map', 422);
    expect(array_keys($missing->json('error.details') ? collect($missing->json('error.details'))->keyBy('field')->all() : []))
        ->toContain('mapping.tax_id', 'mapping.payroll_regime');

    impCall($this->user, 'post', "/imports/{$id}/map", ['mapping' => ['name' => 'No existe', 'tax_id' => 'RUC', 'payroll_regime' => 'Régimen']])->assertStatus(422);
    impCall($this->user, 'post', "/imports/{$id}/map", ['mapping' => ['name' => 'RUC', 'tax_id' => 'RUC', 'payroll_regime' => 'Régimen']])->assertStatus(422);
    expect(impCall($this->user, 'get', "/imports/{$id}")->json('data.status'))->toBe('uploaded');
});

test('RN-IMP-08: el mismo archivo subido dos veces se advierte por su huella', function (): void {
    $first = impUpload($this->user, impSampleCsv());
    expect($first->json('data.summary.duplicate_of'))->toBeNull();

    $second = impUpload($this->user, impSampleCsv());
    expect($second->json('data.summary.duplicate_of'))->toBe($first->json('data.id'))
        ->and($second->json('data.summary.warnings.0.code'))->toBe('duplicate_file');
});

test('archivo Excel (.xlsx) y CSV UTF-8 con comas se leen igual', function (): void {
    $xlsx = impXlsx([['Razón social', 'RUC', 'Régimen'], ['Sierra Alta S.A.C.', '20555555551', 'pe_general'], ['Selva Verde S.A.C.', 20666666661, 'pe_pequena']]);
    $up = impUpload($this->user, $xlsx, 'empresas.xlsx');
    $this->assertMatchesOpenApi($up, 'post', '/imports', 201);
    expect($up->json('data.summary.format'))->toBe('xlsx')
        ->and($up->json('data.total_rows'))->toBe(2);
    $rows = impCall($this->user, 'get', '/imports/'.$up->json('data.id').'/rows')->json('data');
    expect($rows[1]['values'])->toBe(['Razón social' => 'Selva Verde S.A.C.', 'RUC' => '20666666661', 'Régimen' => 'pe_pequena']);

    $utf8 = impCsv([['Razón social', 'RUC', 'Régimen'], ['"Costa, Sur S.A.C."', '20777777771', 'pe_general']], ',', false);
    $csv = impUpload($this->user, "\xEF\xBB\xBF".$utf8);
    expect($csv->json('data.summary.delimiter'))->toBe(',');
    expect(impCall($this->user, 'get', '/imports/'.$csv->json('data.id').'/rows')->json('data.0.values.Razón social'))->toBe('Costa, Sur S.A.C.');

    // Un archivo sin filas de datos se rechaza con el motivo.
    $empty = impUpload($this->user, impCsv([['Razón social', 'RUC']]));
    $this->assertMatchesOpenApi($empty, 'post', '/imports', 422);
});

test('si un lote falla, se deshace toda la importación y queda la fila con el motivo', function (): void {
    $id = (string) impUpload($this->user, impCsv([
        ['Razón social', 'RUC', 'Régimen'],
        ['Primera S.A.C.', '20111111111', 'pe_general'],
        ['Segunda S.A.C.', '20222222222', 'pe_general'],
    ]))->json('data.id');
    impCall($this->user, 'post', "/imports/{$id}/map", ['mapping' => ['name' => 'Razón social', 'tax_id' => 'RUC', 'payroll_regime' => 'Régimen']])->assertOk();
    expect(impCall($this->user, 'post', "/imports/{$id}/validate")->json('data.valid_rows'))->toBe(2);
    // Lotes de una fila; y otra persona registra la segunda empresa entre la validación y la carga.
    impSetParameter('importacion.tamano_lote', 1);
    TenancyScope::runAs(IMP_TENANT, fn () => LegalEntity::factory()->create(['legal_name' => 'Ya existía', 'tax_id' => '20222222222', 'country_code' => 'PE']));
    // Confirmar: el proceso en segundo plano arranca al terminar la petición (import.started).
    expect(impCall($this->user, 'post', "/imports/{$id}/execute", ['confirm' => true])->json('data.batch_size'))->toBe(1);

    $job = impCall($this->user, 'get', "/imports/{$id}")->json('data');
    expect($job['status'])->toBe('failed')
        ->and($job['imported_rows'])->toBe(0)
        ->and($job['summary']['failed_row'])->toBe(3)
        ->and($job['error_message'])->not->toBe('')
        ->and($job['allowed_actions'])->toBe(['cancel']);
    TenancyScope::runAs(IMP_TENANT, function () use ($id): void {
        // La primera empresa (lote 1, ya confirmado) se deshizo: no quedan datos a medias.
        expect(LegalEntity::query()->where('tax_id', '20111111111')->exists())->toBeFalse()
            ->and(LegalEntity::withTrashed()->where('tax_id', '20111111111')->whereNotNull('deleted_at')->exists())->toBeTrue()
            ->and(DB::table('import_job_rows')->where('import_job_id', $id)->pluck('status', 'row_number')->all())->toBe([2 => 'valid', 3 => 'failed'])
            ->and(DB::table('outbox_events')->where('aggregate_id', $id)->where('event_type', 'import.failed.v1')->exists())->toBeTrue();
    });
    $errors = impCall($this->user, 'get', "/imports/{$id}/errors/download");
    expect((string) $errors->getContent())->toContain('Segunda S.A.C.');
});

test('RN-IMP-07: deshacer un lote; si un registro ya se usa, se indica qué lo impide y no se deshace nada', function (): void {
    $id = impImportedJob($this->user);

    // Alguien edita una de las empresas importadas: ya no se puede deshacer.
    TenancyScope::runAs(IMP_TENANT, fn () => LegalEntity::query()->where('tax_id', '20987654321')->firstOrFail()->forceFill(['trade_name' => 'Editada'])->save());
    $blocked = impCall($this->user, 'post', "/imports/{$id}/revert");
    $this->assertMatchesOpenApi($blocked, 'post', '/imports/{id}/revert', 422);
    expect($blocked->json('error.meta.rule') ?? $blocked->json('error.rule'))->toBe('RN-IMP-07');
    expect(TenancyScope::runAs(IMP_TENANT, fn () => LegalEntity::query()->count()))->toBe(2);

    $id2 = impImportedJob($this->user, [['Tercera S.A.C.', '20333333331', 'pe_general']]);
    $reverted = impCall($this->user, 'post', "/imports/{$id2}/revert");
    $this->assertMatchesOpenApi($reverted, 'post', '/imports/{id}/revert', 200);
    expect($reverted->json('data.status'))->toBe('reverted');
    TenancyScope::runAs(IMP_TENANT, function () use ($id2): void {
        expect(LegalEntity::query()->where('tax_id', '20333333331')->exists())->toBeFalse()
            ->and(DB::table('audit_events')->where('action', 'legal_entity.import_reverted')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('entity_id', $id2)->where('action', 'import.reverted')->exists())->toBeTrue();
    });
    impCall($this->user, 'post', "/imports/{$id2}/revert")->assertStatus(409);
});

test('RN-IMP-04: una columna sensible sin el permiso del campo se ignora con aviso', function (): void {
    app(ImportRegistry::class)->register(new ImpFakePositionsImporter);
    $user = IdentityFixtures::userWithPermissions(IMP_TENANT, array_merge(IMP_CAN, ['org.positions.create']));
    $id = (string) impUpload($user, impCsv([['Código', 'Sueldo'], ['P-1', '4500']]), 'pos.csv', 'positions')->json('data.id');

    $mapped = impCall($user, 'post', "/imports/{$id}/map", ['mapping' => ['code' => 'Código', 'salary' => 'Sueldo']]);
    $this->assertMatchesOpenApi($mapped, 'post', '/imports/{id}/map', 200);
    expect($mapped->json('data.mapping'))->toBe(['code' => 'Código'])
        ->and($mapped->json('data.summary.ignored_columns'))->toBe(['salary'])
        ->and($mapped->json('data.summary.warnings.0.code'))->toBe('sensitive_ignored');

    $types = collect(impCall($user, 'get', '/import-types')->json('data'))->firstWhere('entity', 'positions');
    expect(collect($types['columns'])->firstWhere('key', 'salary'))->toMatchArray(['sensitive' => true, 'allowed' => false]);
});

test('permisos: sin permiso 403, sin el permiso del módulo dueño 403, módulo apagado 403 y datos inválidos 422', function (): void {
    $viewer = IdentityFixtures::userWithPermissions(IMP_TENANT, ['imports.imports.view']);
    $forbidden = impUpload($viewer, impSampleCsv());
    $this->assertMatchesOpenApi($forbidden, 'post', '/imports', 403);

    // Puede crear importaciones pero no empresas: no puede importarlas (ni las ve como tipo).
    $noOwner = IdentityFixtures::userWithPermissions(IMP_TENANT, ['imports.imports.view', 'imports.imports.create']);
    impUpload($noOwner, impSampleCsv())->assertStatus(403)->assertJsonPath('error.meta.permission', 'org.legal_entities.create');
    expect(collect(impCall($noOwner, 'get', '/import-types')->json('data'))->pluck('entity')->all())->not->toContain('legal_entities');

    $invalid = impCall($this->user, 'post', '/imports', ['entity' => 'legal_entities', 'content_base64' => '***']);
    $this->assertMatchesOpenApi($invalid, 'post', '/imports', 422);
    impCall($this->user, 'post', '/imports', ['entity' => 'no_existe', 'content_base64' => base64_encode(impSampleCsv())])->assertStatus(422);

    IdentityFixtures::disableModule(IMP_TENANT, 'core');
    impCall($this->user, 'get', '/imports')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('aislamiento: otra organización no ve ni opera las importaciones de A', function (): void {
    $id = (string) impUpload($this->user, impSampleCsv())->json('data.id');
    $intruder = IdentityFixtures::userWithPermissions(IMP_OTHER, IMP_CAN);

    $this->assertMatchesOpenApi(impCall($intruder, 'get', "/imports/{$id}"), 'get', '/imports/{id}', 404);
    impCall($intruder, 'get', "/imports/{$id}/rows")->assertNotFound();
    impCall($intruder, 'post', "/imports/{$id}/cancel")->assertNotFound();
    expect(impCall($intruder, 'get', '/imports')->json('data'))->toBe([]);
});

test('máquina de estados ME-41: cada estado solo admite sus acciones', function (): void {
    $machine = new ImportJobStateMachine;
    $states = ['uploaded', 'mapped', 'with_errors', 'validated', 'importing', 'completed', 'failed', 'cancelled', 'reverted'];
    $actions = ['map', 'validate', 'validate_with_errors', 'execute', 'finish', 'fail', 'cancel', 'revert'];
    $allowed = [
        'uploaded' => ['map', 'cancel'], 'mapped' => ['validate', 'validate_with_errors', 'cancel'],
        'with_errors' => ['validate', 'validate_with_errors', 'cancel'], 'validated' => ['execute', 'cancel'],
        'importing' => ['finish', 'fail'], 'completed' => ['revert'], 'failed' => ['cancel'], 'cancelled' => [], 'reverted' => [],
    ];
    foreach ($states as $state) {
        foreach ($actions as $action) {
            expect($machine->can($state, $action))->toBe(in_array($action, $allowed[$state], true), "{$state} × {$action}");
        }
    }
});

test('el lector interpreta fechas mixtas, decimales y detecta el separador', function (): void {
    expect(ImportValues::date('2026-03-01'))->toBe('2026-03-01')
        ->and(ImportValues::date('01/03/2026'))->toBe('2026-03-01')
        ->and(ImportValues::date('46082'))->toBe('2026-03-01')
        ->and(ImportValues::date('31/02/2026'))->toBeNull()
        ->and(ImportValues::decimal('1.234,50'))->toBe('1234.50')
        ->and(ImportValues::decimal('1,234.50'))->toBe('1234.50')
        ->and(ImportValues::decimal('abc'))->toBeNull();
    $table = (new SpreadsheetReader)->read("a\tb\n1\t2\n\n3\t4\n", 'x.tsv', 10);
    expect($table['delimiter'])->toBe("\t")->and($table['rows'])->toBe([2 => ['1', '2'], 4 => ['3', '4']]);
});

/**
 * Importación completada de las dos empresas válidas de ejemplo (o de las filas dadas).
 *
 * @param  array<int, array<int, string>>|null  $rows
 */
function impImportedJob(User $user, ?array $rows = null): string
{
    $lines = array_merge([['Razón social', 'RUC', 'Régimen']], $rows ?? [['Andina Servicios S.A.C.', '20123456781', 'pe_general'], ['Pacífico Norte S.A.', '20987654321', 'pe_micro']]);
    $id = (string) impUpload($user, impCsv($lines))->json('data.id');
    impCall($user, 'post', "/imports/{$id}/map", ['mapping' => ['name' => 'Razón social', 'tax_id' => 'RUC', 'payroll_regime' => 'Régimen']])->assertOk();
    impCall($user, 'post', "/imports/{$id}/validate")->assertOk();
    impCall($user, 'post', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
    impRun($id);
    expect(TenancyScope::runAs(IMP_TENANT, fn () => ImportJob::query()->whereKey($id)->value('status')))->toBe('completed');

    return $id;
}

/** XLSX mínimo con textos compartidos y números, como lo guarda Excel. */
function impXlsx(array $rows): string
{
    $shared = [];
    $sheet = '';
    foreach ($rows as $r => $cells) {
        $sheet .= '<row r="'.($r + 1).'">';
        foreach (array_values($cells) as $c => $value) {
            $ref = chr(65 + $c).($r + 1);
            if (is_int($value)) {
                $sheet .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
            } else {
                $shared[] = $value;
                $sheet .= '<c r="'.$ref.'" t="s"><v>'.(count($shared) - 1).'</v></c>';
            }
        }
        $sheet .= '</row>';
    }
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $shared)).'</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheet.'</sheetData></worksheet>');
    $zip->close();
    $contents = (string) file_get_contents($path);
    @unlink($path);

    return $contents;
}

/** Importador de prueba con una columna sensible (RN-IMP-04). No escribe nada. */
final class ImpFakePositionsImporter implements Importer
{
    public function entity(): string
    {
        return 'positions';
    }

    public function permission(): string
    {
        return 'org.positions.create';
    }

    public function columns(): array
    {
        return [new ImportColumn('code', true, 'P-1'), new ImportColumn('salary', false, '4500', [], 'people.employments.salary.view')];
    }

    public function naturalKey(array $data): ?string
    {
        return $data['code'] ?? null;
    }

    public function validate(array $data, ImportContext $context): RowCheck
    {
        return new RowCheck($data);
    }

    public function import(array $parsed, ImportContext $context): array
    {
        return ['entity_id' => (string) Str::uuid(), 'previous' => null];
    }

    public function blockingReason(string $entityId, ImportContext $context): ?string
    {
        return null;
    }

    public function revert(string $entityId, ?array $previous, ImportContext $context): void {}
}
