<?php

/**
 * IMP-2 · Importadores de Core HR (estructura, puestos, posiciones, colaboradores, históricos de
 * movimientos y sueldos) e IMP-6 · informe del lote (creadas, actualizadas, rechazadas), archivo
 * original y reversión que deja los datos como estaban.
 */

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const I2_TENANT = 'aaaaaaaa-1a72-4000-8000-00000000000a';
const I2_RUC = '20111222331';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => I2_TENANT, 'slug' => 'imp2-org', 'name' => 'Imp2', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [I2_TENANT]);
    TenancyScope::runAs(I2_TENANT, fn () => IdentityFixtures::enableAllModules());
    $this->admin = i2User('admin_empresa');
    TenancyScope::runAs(I2_TENANT, function (): void {
        $entity = LegalEntity::factory()->create(['tax_id' => I2_RUC]);
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        Site::factory()->create(['legal_entity_id' => $entity->id, 'code' => 'SC', 'name' => 'Sede central']);
    });
});

afterEach(fn () => TenancyScope::clear());

function i2User(string $role): User
{
    return TenancyScope::runAs(I2_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function i2Call(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => I2_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/** @param  list<list<string>>  $lines */
function i2Csv(array $lines): string
{
    return implode("\n", array_map(fn ($cells) => implode(';', $cells), $lines))."\n";
}

/**
 * Sube, mapea con la propuesta, valida y, si se pide, confirma y procesa en segundo plano.
 *
 * @param  list<list<string>>  $lines
 * @return array<string, mixed>
 */
function i2Import(User $user, string $entity, array $lines, bool $execute = true): array
{
    $up = i2Call($user, 'post', '/imports', ['entity' => $entity, 'file_name' => "{$entity}.csv", 'content_base64' => base64_encode(i2Csv($lines))]);
    $up->assertCreated();
    $id = (string) $up->json('data.id');
    i2Call($user, 'post', "/imports/{$id}/map", ['mapping' => $up->json('data.suggested_mapping')])->assertOk();
    $validated = i2Call($user, 'post', "/imports/{$id}/validate")->assertOk();
    if (! $execute) {
        return (array) $validated->json('data');
    }
    i2Call($user, 'post', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
    TenancyScope::runAs(I2_TENANT, fn () => app(ImportRunner::class)->run($id));

    return (array) i2Call($user, 'get', "/imports/{$id}")->json('data');
}

/** Estructura mínima cargada por importación: unidades, puestos y posiciones. */
function i2Structure(User $user): void
{
    $units = i2Import($user, 'org_structure', [
        ['Código', 'Nombre', 'Tipo de unidad', 'Código de la unidad superior', 'Empresa (RUC)'],
        ['ADM', 'Administración', 'Gerencia', '', I2_RUC],
        ['ADM-FIN', 'Finanzas', 'Área', 'ADM', ''],
    ]);
    expect($units['status'])->toBe('completed')->and($units['created_rows'])->toBe(2);
    $jobs = i2Import($user, 'job_profiles', [
        ['Código', 'Nombre del puesto'],
        ['GER-ADM', 'Gerente de administración'],
        ['ANL-CONT', 'Analista contable'],
    ]);
    expect($jobs['status'])->toBe('completed');
    $positions = i2Import($user, 'positions', [
        ['Código de la posición', 'Empresa (RUC)', 'Código del puesto', 'Código de la unidad', 'Posición a la que reporta'],
        ['POS-001', I2_RUC, 'GER-ADM', 'ADM', ''],
        ['POS-002', I2_RUC, 'ANL-CONT', 'ADM-FIN', 'POS-001'],
    ]);
    expect($positions['status'])->toBe('completed')->and($positions['imported_rows'])->toBe(2);
}

test('IMP-2 · estructura, puestos y posiciones: la unidad superior y la posición jefe pueden venir antes en el mismo archivo', function (): void {
    $types = i2Call($this->admin, 'get', '/import-types');
    $this->assertMatchesOpenApi($types, 'get', '/import-types', 200);
    expect(collect($types->json('data'))->pluck('entity')->all())
        ->toContain('legal_entities', 'org_structure', 'job_profiles', 'positions', 'employees', 'history', 'salaries');

    i2Structure($this->admin);

    TenancyScope::runAs(I2_TENANT, function (): void {
        $adm = DB::table('org_units')->where('code', 'ADM')->first();
        $fin = DB::table('org_units')->where('code', 'ADM-FIN')->first();
        expect($fin->parent_id)->toBe($adm->id)->and($fin->legal_entity_id)->toBe($this->entity);
        $boss = Position::query()->where('code', 'POS-001')->firstOrFail();
        $analyst = Position::query()->where('code', 'POS-002')->firstOrFail();
        // Quien importa puede aprobar posiciones: quedan aprobadas con la misma acción del alta manual.
        expect($analyst->reports_to_position_id)->toBe($boss->id)
            ->and($analyst->status)->toBe('approved')
            ->and($analyst->title)->toBe('Analista contable')
            ->and(DB::table('audit_events')->where('action', 'org_unit.created')->count())->toBe(2);
    });
});

test('IMP-2 · colaboradores: duplicados por documento, persona existente reutilizada, jefe del mismo archivo y sueldo de ingreso', function (): void {
    i2Structure($this->admin);
    $existing = i2Call($this->admin, 'post', '/people', ['first_names' => 'Ana', 'paternal_surname' => 'Rojas', 'document_type' => 'dni', 'document_number' => '41234560'])->assertCreated();

    $header = ['Número de documento', 'Nombres', 'Apellido paterno', 'Empresa (RUC)', 'Fecha de ingreso', 'Código de la posición', 'Tipo de contrato', 'Documento del jefe', 'Sueldo básico', 'Sede'];
    $job = i2Import($this->admin, 'employees', [
        $header,
        ['41234567', 'Carlos', 'Vega', I2_RUC, '02/01/2024', 'POS-001', 'indeterminado', '', '9000.00', 'SC'],
        ['41234560', 'Ana', 'Rojas', I2_RUC, '15/03/2024', 'POS-002', 'Indeterminado', '41234567', '3.500,50', 'Sede central'],
        ['41234567', 'Carlos', 'Repetido', I2_RUC, '02/01/2024', 'POS-001', 'indeterminado', '', '', 'SC'],
        ['4999', 'Sin', 'Formato', I2_RUC, '31/02/2024', 'POS-404', 'no-existe', '', '', 'SC'],
    ], execute: false);
    expect($job['status'])->toBe('validated')->and($job['valid_rows'])->toBe(2)->and($job['error_rows'])->toBe(2);
    $rows = i2Call($this->admin, 'get', "/imports/{$job['id']}/rows");
    $byRow = collect($rows->json('data'))->keyBy('row_number');
    expect(collect($byRow[3]['warnings'])->pluck('code')->all())->toContain('person_exists')
        ->and(collect($byRow[4]['errors'])->pluck('code')->all())->toContain('duplicate_in_file')
        ->and(collect($byRow[5]['errors'])->pluck('column')->all())->toContain('hire_date', 'position_code', 'contract_type');
    // Validar no escribe nada en Core HR.
    expect(TenancyScope::runAs(I2_TENANT, fn () => Employment::query()->count()))->toBe(0);

    i2Call($this->admin, 'post', "/imports/{$job['id']}/execute", ['confirm' => true])->assertOk();
    TenancyScope::runAs(I2_TENANT, fn () => app(ImportRunner::class)->run((string) $job['id']));
    $done = i2Call($this->admin, 'get', "/imports/{$job['id']}");
    $this->assertMatchesOpenApi($done, 'get', '/imports/{id}', 200);
    expect($done->json('data.status'))->toBe('completed')
        ->and($done->json('data.created_rows'))->toBe(2)
        ->and($done->json('data.updated_rows'))->toBe(0)
        ->and($done->json('data.rejected_rows'))->toBe(2);

    TenancyScope::runAs(I2_TENANT, function () use ($existing): void {
        $ana = Employment::query()->where('person_id', $existing->json('data.id'))->firstOrFail();
        $carlos = Employment::query()->where('person_id', '<>', $existing->json('data.id'))->firstOrFail();
        $assignment = DB::table('employment_assignments')->where('employment_id', $ana->id)->first();
        expect($assignment->manager_employment_id)->toBe($carlos->id)
            ->and(DB::table('employment_compensations')->where('employment_id', $ana->id)->value('base_salary'))->toBe('3500.50')
            ->and(DB::table('people')->count())->toBe(2)
            ->and(Position::query()->where('code', 'POS-002')->value('status'))->toBe('filled');
    });
});

test('IMP-2 · RN-IMP-04: sin el permiso del sueldo la columna se ignora con aviso y el ingreso se importa sin sueldo', function (): void {
    i2Structure($this->admin);
    $hr = i2User('rrhh');
    TenancyScope::runAs(I2_TENANT, function () use ($hr): void {
        DB::table('role_permissions')->whereIn('role_id', DB::table('user_roles')->where('user_id', $hr->id)->pluck('role_id'))
            ->where('permission_code', 'people.employments.salary.edit')->delete();
    });
    $job = i2Import($hr, 'employees', [
        ['Número de documento', 'Nombres', 'Apellido paterno', 'Empresa (RUC)', 'Fecha de ingreso', 'Código de la posición', 'Tipo de contrato', 'Sueldo básico', 'Sede'],
        ['41234567', 'Carlos', 'Vega', I2_RUC, '02/01/2024', 'POS-001', 'indeterminado', '9000.00', 'SC'],
    ]);
    expect($job['status'])->toBe('completed')
        ->and(collect($job['summary']['warnings'] ?? [])->pluck('code')->all())->toContain('sensitive_ignored');
    expect(TenancyScope::runAs(I2_TENANT, fn () => DB::table('employment_compensations')->count()))->toBe(0);
});

test('IMP-2 · históricos de sueldos y movimientos: tramos con vigencia, marcados «importado» y sin eventos; IMP-6 deshacer los deja como estaban', function (): void {
    i2Structure($this->admin);
    i2Import($this->admin, 'employees', [
        ['Número de documento', 'Nombres', 'Apellido paterno', 'Empresa (RUC)', 'Fecha de ingreso', 'Código del puesto', 'Código de la unidad', 'Tipo de contrato', 'Sueldo básico', 'Sede'],
        ['41234567', 'Carlos', 'Vega', I2_RUC, '02/01/2022', 'ANL-CONT', 'ADM-FIN', 'indeterminado', '3000', 'SC'],
    ]);
    $employment = TenancyScope::runAs(I2_TENANT, fn () => Employment::query()->firstOrFail());

    $salaries = i2Import($this->admin, 'salaries', [
        ['Número de documento', 'Empresa (RUC)', 'Vigente desde', 'Sueldo básico', 'Motivo del cambio'],
        ['41234567', I2_RUC, '01/01/2023', '3300', 'merit'],
        ['41234567', I2_RUC, '01/01/2024', '3600', 'adjustment'],
        ['41234567', I2_RUC, '01/06/2023', '3400', 'merit'],
    ]);
    expect($salaries['status'])->toBe('completed')->and($salaries['created_rows'])->toBe(2)->and($salaries['rejected_rows'])->toBe(1);

    $moves = i2Import($this->admin, 'history', [
        ['Número de documento', 'Empresa (RUC)', 'Fecha del movimiento', 'Código del puesto', 'Código de la unidad', 'Motivo'],
        ['41234567', I2_RUC, '01/02/2025', 'GER-ADM', 'ADM', 'Promoción'],
    ]);
    expect($moves['status'])->toBe('completed')->and($moves['created_rows'])->toBe(1);

    TenancyScope::runAs(I2_TENANT, function () use ($employment): void {
        $at = fn (string $table, string $date, string $column) => DB::table($table)->where('employment_id', $employment->id)
            ->where('valid_from', '<=', $date)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))->value($column);
        expect($at('employment_compensations', '2022-06-01', 'base_salary'))->toBe('3000.00')
            ->and($at('employment_compensations', '2023-03-01', 'base_salary'))->toBe('3300.00')
            ->and($at('employment_compensations', '2025-01-01', 'base_salary'))->toBe('3600.00')
            ->and($at('employment_assignments', '2024-12-31', 'job_profile_id'))->toBe(DB::table('job_profiles')->where('code', 'ANL-CONT')->value('id'))
            ->and($at('employment_assignments', '2025-03-01', 'job_profile_id'))->toBe(DB::table('job_profiles')->where('code', 'GER-ADM')->value('id'));
        $action = DB::table('personnel_actions')->where('employment_id', $employment->id)->where('kind', 'promotion')->first();
        expect(json_decode((string) $action->payload, true)['imported'])->toBeTrue()
            ->and($action->status)->toBe('applied')
            // RN-IMP-05: sin eventos de negocio (ni employee.updated ni employee.promoted).
            ->and(DB::table('outbox_events')->where('aggregate_id', $employment->id)->whereIn('event_type', ['employee.updated.v1', 'employee.promoted.v1'])->count())->toBe(0);
    });

    // El ingreso no se puede deshacer mientras sus históricos existen (RN-IMP-07).
    $employees = collect(i2Call($this->admin, 'get', '/imports?filter[entity]=employees')->json('data'))->first();
    $blocked = i2Call($this->admin, 'post', "/imports/{$employees['id']}/revert");
    $this->assertMatchesOpenApi($blocked, 'post', '/imports/{id}/revert', 422);

    // Deshacer en orden inverso deja todo como estaba.
    i2Call($this->admin, 'post', "/imports/{$moves['id']}/revert")->assertOk();
    i2Call($this->admin, 'post', "/imports/{$salaries['id']}/revert")->assertOk();
    TenancyScope::runAs(I2_TENANT, function () use ($employment): void {
        expect(DB::table('employment_compensations')->where('employment_id', $employment->id)->get(['base_salary', 'valid_to'])->map(fn ($r) => (array) $r)->all())
            ->toBe([['base_salary' => '3000.00', 'valid_to' => null]])
            ->and(DB::table('employment_assignments')->where('employment_id', $employment->id)->count())->toBe(1)
            ->and(DB::table('employment_assignments')->where('employment_id', $employment->id)->value('valid_to'))->toBeNull();
    });
    $undone = i2Call($this->admin, 'post', "/imports/{$employees['id']}/revert");
    $this->assertMatchesOpenApi($undone, 'post', '/imports/{id}/revert', 200);
    expect($undone->json('data.status'))->toBe('reverted');
    TenancyScope::runAs(I2_TENANT, function (): void {
        expect(Employment::query()->count())->toBe(0)
            ->and(DB::table('people')->whereNull('deleted_at')->count())->toBe(0)
            ->and(DB::table('positions')->whereNull('deleted_at')->where('is_implicit', true)->count())->toBe(0);
    });

    // Regla 2: nada laboral se borró físicamente. Para la aplicación ya no existen, pero siguen en base con
    // deleted_at (papelera): el ingreso, el movimiento y los tres tramos de sueldo deshechos.
    TenancyScope::runAs(I2_TENANT, function () use ($employment): void {
        DB::statement('RESET ROLE');
        try {
            $trashed = fn (string $table) => DB::table($table)->where('employment_id', $employment->id)->get(['deleted_at'])
                ->map(fn ($r) => $r->deleted_at !== null)->all();
            expect($trashed('employment_assignments'))->toBe([true, true])
                ->and($trashed('employment_compensations'))->toBe([true, true, true])
                ->and($trashed('employment_labor_regimes'))->toBe([true])
                ->and(DB::table('employments')->where('id', $employment->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
        } finally {
            DB::statement('SET ROLE gestia_app');
        }
    });
});

test('IMP-6 · historial con autor y resultado, descarga del archivo original y del informe de errores', function (): void {
    $lines = [['Código', 'Nombre del puesto'], ['ANL-CONT', 'Analista contable'], ['', 'Sin código']];
    $job = i2Import($this->admin, 'job_profiles', $lines);
    expect($job['status'])->toBe('completed')->and($job['created_rows'])->toBe(1)->and($job['rejected_rows'])->toBe(1)
        ->and($job['created_by_name'])->not->toBeNull();

    $file = i2Call($this->admin, 'get', "/imports/{$job['id']}/file");
    $this->assertMatchesOpenApi($file, 'get', '/imports/{id}/file', 200);
    expect($file->getContent())->toBe(i2Csv($lines))
        ->and((string) $file->headers->get('Content-Disposition'))->toContain('job_profiles.csv');
    i2Call($this->admin, 'get', "/imports/{$job['id']}/errors/download")->assertOk();

    // Un lote descartado ya no tiene archivo (409); sin permiso de ver importaciones, 403.
    $up = i2Call($this->admin, 'post', '/imports', ['entity' => 'job_profiles', 'file_name' => 'otro.csv', 'content_base64' => base64_encode(i2Csv([['Código', 'Nombre del puesto'], ['X', 'Y']]))]);
    i2Call($this->admin, 'post', '/imports/'.$up->json('data.id').'/cancel')->assertOk();
    $gone = i2Call($this->admin, 'get', '/imports/'.$up->json('data.id').'/file');
    $this->assertMatchesOpenApi($gone, 'get', '/imports/{id}/file', 409);
    $employee = i2User('empleado');
    $forbidden = i2Call($employee, 'get', "/imports/{$job['id']}/file");
    $this->assertMatchesOpenApi($forbidden, 'get', '/imports/{id}/file', 403);

    TenancyScope::runAs(I2_TENANT, fn () => expect(DB::table('audit_events')->where('entity_id', $job['id'])->where('action', 'import.file_downloaded')->count())->toBe(1));
});

test('IMP-2 · el mapeo guardado se propone en la siguiente importación del mismo tipo', function (): void {
    $lines = [['COD', 'DESCRIPCION'], ['ANL-CONT', 'Analista contable']];
    $up = i2Call($this->admin, 'post', '/imports', ['entity' => 'job_profiles', 'file_name' => 'a.csv', 'content_base64' => base64_encode(i2Csv($lines))]);
    i2Call($this->admin, 'post', '/imports/'.$up->json('data.id').'/map', ['mapping' => ['code' => 'COD', 'name' => 'DESCRIPCION'], 'save_as' => 'Puestos del sistema anterior'])->assertOk();
    $again = i2Call($this->admin, 'post', '/imports', ['entity' => 'job_profiles', 'file_name' => 'b.csv', 'content_base64' => base64_encode(i2Csv([['COD', 'DESCRIPCION'], ['JEF', 'Jefe']]))]);
    expect($again->json('data.suggested_mapping'))->toBe(['code' => 'COD', 'name' => 'DESCRIPCION']);
});

test('IMP-2 · sin el permiso del módulo dueño no se importa ese tipo de datos (403)', function (): void {
    $employee = i2User('empleado');
    $denied = i2Call($employee, 'post', '/imports', ['entity' => 'employees', 'file_name' => 'x.csv', 'content_base64' => base64_encode('a;b')]);
    $this->assertMatchesOpenApi($denied, 'post', '/imports', 403);
});
