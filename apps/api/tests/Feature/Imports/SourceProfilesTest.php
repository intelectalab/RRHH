<?php

/**
 * IMP-7 · Importar exportaciones de otros sistemas de RRHH y planilla con perfiles de origen
 * versionados: el archivo entra sin reordenar columnas, cada fila rechazada dice campo, valor y
 * corrección esperada, un perfil nuevo es configuración y se usan los mismos validadores y reversión.
 */

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Imports\Application\ImportRunner;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
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

const I7_TENANT = 'aaaaaaaa-1a77-4000-8000-00000000000b';
const I7_OTHER = 'aaaaaaaa-1a77-4000-8000-00000000000c';
const I7_RUC = '20111222771';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Storage::fake('s3');
    config(['files.disk' => 's3']);
    TenancyScope::clear();
    foreach ([I7_TENANT => 'imp7-org', I7_OTHER => 'imp7-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => $slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = i7User(I7_TENANT, 'admin_empresa');
    TenancyScope::runAs(I7_TENANT, function (): void {
        $entity = LegalEntity::factory()->create(['tax_id' => I7_RUC]);
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        Site::factory()->create(['legal_entity_id' => $entity->id, 'code' => 'SC', 'name' => 'Sede central']);
        OrgUnit::factory()->create(['legal_entity_id' => $entity->id, 'code' => 'FIN', 'name' => 'Finanzas']);
        JobProfile::factory()->create(['code' => 'ANL', 'name' => 'Analista']);
    });
});

afterEach(fn () => TenancyScope::clear());

function i7User(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function i7Call(User $user, string $method, string $path, array $body = [], string $tenant = I7_TENANT): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $tenant, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/** @param  list<list<string>>  $lines */
function i7Csv(array $lines): string
{
    return implode("\n", array_map(fn ($cells) => implode(';', $cells), $lines))."\n";
}

test('IMP-7 · una exportación de planilla tradicional entra tal cual: perfil detectado, códigos traducidos, errores explicados y misma reversión', function (): void {
    $lines = [
        ['COD_TRAB', 'TIPO_DOC', 'NRO_DOC', 'AP_PATERNO', 'NOMBRES', 'RUC_EMPRESA', 'FEC_ING', 'TIPO_CONTRATO', 'SEDE', 'COD_AREA', 'COD_CARGO', 'MONEDA', 'SUELDO_BASICO'],
        ['T001', 'DNI', '41234567', 'Vega', 'Carlos', I7_RUC, '02/01/2024', 'indeterminado', 'SC', 'FIN', 'ANL', 'S/', '4500.00'],
        ['T002', 'DNI', '41234568', 'Rojas', 'Ana', I7_RUC, '31/02/2024', 'indeterminado', 'SC', 'FIN', 'ANL', 'SOLES', '3200.00'],
    ];
    $up = i7Call($this->admin, 'post', '/imports', ['entity' => 'employees', 'file_name' => 'planilla.csv', 'content_base64' => base64_encode(i7Csv($lines))])->assertCreated();
    $this->assertMatchesOpenApi($up, 'post', '/imports', 201);
    expect(collect($up->json('data.suggested_profiles'))->pluck('code')->first())->toBe('pe_planilla_tradicional')
        ->and($up->json('data.suggested_mapping.document_number'))->toBe('NRO_DOC');
    $id = (string) $up->json('data.id');

    // Solo el perfil: sin reordenar columnas ni mapear a mano.
    $mapped = i7Call($this->admin, 'post', "/imports/{$id}/map", ['mapping' => [], 'profile_code' => 'pe_planilla_tradicional'])->assertOk();
    expect($mapped->json('data.mapping.hire_date'))->toBe('FEC_ING')
        ->and($mapped->json('data.summary.profile.version'))->toBe(1)
        ->and($mapped->json('data.summary.profile.to_validate'))->toBeTrue();

    $validated = i7Call($this->admin, 'post', "/imports/{$id}/validate")->assertOk();
    expect($validated->json('data.valid_rows'))->toBe(1)->and($validated->json('data.error_rows'))->toBe(1);
    $rows = collect(i7Call($this->admin, 'get', "/imports/{$id}/rows")->json('data'))->keyBy('row_number');
    $error = collect($rows[3]['errors'])->firstWhere('column', 'hire_date');
    expect($error['header'])->toBe('FEC_ING')->and($error['value'])->toBe('31/02/2024')->and($error['expected'])->toContain('05/01/2026');
    $csv = i7Call($this->admin, 'get', "/imports/{$id}/errors/download")->assertOk()->getContent();
    expect($csv)->toContain('05/01/2026');

    i7Call($this->admin, 'post', "/imports/{$id}/execute", ['confirm' => true])->assertOk();
    TenancyScope::runAs(I7_TENANT, fn () => app(ImportRunner::class)->run($id));
    expect(i7Call($this->admin, 'get', "/imports/{$id}")->json('data.status'))->toBe('completed');
    TenancyScope::runAs(I7_TENANT, function (): void {
        // «S/» del origen llegó como PEN.
        expect(Employment::query()->count())->toBe(1)
            ->and(DB::table('employment_compensations')->value('currency'))->toBe('PEN');
    });

    // La misma reversión de IMP-6: nada laboral se borra físicamente.
    i7Call($this->admin, 'post', "/imports/{$id}/revert")->assertOk();
    TenancyScope::runAs(I7_TENANT, fn () => expect(Employment::query()->count())->toBe(0));
});

test('IMP-7 · un perfil propio o corregido es configuración versionada; otra organización no lo ve', function (): void {
    $body = ['code' => 'mi_sistema', 'entity' => 'employees', 'name' => 'Mi sistema anterior',
        'columns' => ['document_number' => ['DOC'], 'first_names' => ['NOMBRE'], 'paternal_surname' => ['APELLIDO'], 'legal_entity' => ['RUC'], 'hire_date' => ['INGRESO'], 'site' => ['LOCAL']],
        'value_maps' => ['contract_type' => ['P' => 'indeterminado']], 'defaults' => ['contract_type' => 'indeterminado', 'org_unit_code' => 'FIN', 'job_profile_code' => 'ANL']];
    $first = i7Call($this->admin, 'post', '/import-profiles', $body)->assertCreated();
    $this->assertMatchesOpenApi($first, 'post', '/import-profiles', 201);
    expect($first->json('data.version'))->toBe(1)->and($first->json('data.origin'))->toBe('organization');

    $second = i7Call($this->admin, 'post', '/import-profiles', ['columns' => $body['columns'] + ['employee_code' => ['CODIGO']]] + $body)->assertCreated();
    expect($second->json('data.version'))->toBe(2)->and(count($second->json('data.versions')))->toBe(2);

    // Corregir un perfil del paquete del país crea la versión siguiente de la organización.
    $fixed = i7Call($this->admin, 'post', '/import-profiles', ['code' => 'pe_planilla_tradicional', 'entity' => 'org_structure', 'name' => 'Planilla (corregido)', 'columns' => ['code' => ['AREA_ID'], 'name' => ['AREA_NOMBRE']], 'defaults' => ['type' => 'Área']])->assertCreated();
    expect($fixed->json('data.version'))->toBe(2)->and($fixed->json('data.to_validate'))->toBeTrue();

    $list = i7Call($this->admin, 'get', '/import-profiles?filter[code]=mi_sistema')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/import-profiles', 200);
    expect($list->json('data.0.version'))->toBe(2)->and($list->json('data.0.columns.employee_code'))->toBe(['CODIGO']);

    // Un archivo con el perfil propio: contract_type sale del valor por defecto (columna obligatoria sin cabecera).
    $up = i7Call($this->admin, 'post', '/imports', ['entity' => 'employees', 'file_name' => 'mio.csv', 'content_base64' => base64_encode(i7Csv([
        ['DOC', 'NOMBRE', 'APELLIDO', 'RUC', 'INGRESO', 'LOCAL'],
        ['41234569', 'Luis', 'Paz', I7_RUC, '01/02/2024', 'SC'],
    ]))])->assertCreated();
    $id = (string) $up->json('data.id');
    i7Call($this->admin, 'post', "/imports/{$id}/map", ['mapping' => [], 'profile_code' => 'no_hay'])->assertStatus(422);
    i7Call($this->admin, 'post', "/imports/{$id}/map", ['mapping' => [], 'profile_code' => 'mi_sistema'])->assertOk();
    expect(i7Call($this->admin, 'post', "/imports/{$id}/validate")->json('data.valid_rows'))->toBe(1);

    // Validación: columna desconocida y código inválido.
    i7Call($this->admin, 'post', '/import-profiles', ['code' => 'X', 'entity' => 'employees', 'name' => 'x', 'columns' => ['no_existe' => ['A']]])
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // Sin permiso de mapear no se crean perfiles; otra organización no ve los de esta.
    i7Call(i7User(I7_TENANT, 'empleado'), 'post', '/import-profiles', $body)->assertForbidden();
    $other = i7Call(i7User(I7_OTHER, 'admin_empresa'), 'get', '/import-profiles?filter[code]=mi_sistema', [], I7_OTHER)->assertOk();
    expect($other->json('data'))->toBe([]);
});
