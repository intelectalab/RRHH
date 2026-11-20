<?php

/**
 * CFG-5 · Los campos personalizados se comportan como nativos: columnas y filtros de tablas,
 * exportación, importación y API con su tipo validado, sin código por módulo.
 */

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Imports\ImportContext;
use App\Shared\Imports\ImportRegistry;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const CF5_TENANT = 'aaaaaaaa-6365-4000-8000-00000000005a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => CF5_TENANT, 'slug' => 'cf5-org', 'name' => 'Grupo CF5', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [CF5_TENANT]);
    DB::table('tenant_modules')->updateOrInsert(['tenant_id' => CF5_TENANT, 'module_code' => 'core'], ['status' => 'active']);
    $this->admin = cf5User('admin_empresa');
    // Ve la tabla de colaboradores y exporta, pero no los sueldos (ni el bono, que exige ese permiso por campo).
    $this->rrhh = cf5RoleUser('analista', ['people.employments.view', 'reports.exports.create', 'reports.exports.view']);
    Storage::fake('s3');
    [$this->ventas, $this->planta] = TenancyScope::runAs(CF5_TENANT, fn () => [Employment::factory()->create(), Employment::factory()->create()]);
    cf5Call($this->admin, 'POST', '/custom-fields', ['entity' => 'employment', 'key' => 'area_comercial', 'label' => 'Área comercial', 'type' => 'select', 'options' => ['Ventas', 'Planta'], 'required' => false, 'sensitive' => false])->assertCreated();
    cf5Call($this->admin, 'POST', '/custom-fields', ['entity' => 'employment', 'key' => 'bono_meta', 'label' => 'Bono de meta', 'type' => 'number', 'required' => false, 'sensitive' => true, 'permission' => 'people.employments.salary.view'])->assertCreated();
});

afterEach(fn () => TenancyScope::clear());

function cf5User(string $role): User
{
    return TenancyScope::runAs(CF5_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<int, string>  $permissions
 */
function cf5RoleUser(string $code, array $permissions): User
{
    return TenancyScope::runAs(CF5_TENANT, function () use ($code, $permissions): User {
        $roleId = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $roleId, 'tenant_id' => CF5_TENANT, 'code' => $code, 'name' => ucfirst($code), 'is_system' => false]);
        foreach ($permissions as $p) {
            DB::table('role_permissions')->insert(['tenant_id' => CF5_TENANT, 'role_id' => $roleId, 'permission_code' => $p, 'scope' => 'organization']);
        }
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => $roleId]);

        return $u;
    });
}

function cf5Call(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

test('la API guarda y devuelve el campo con su tipo validado; un valor fuera del tipo es 422', function (): void {
    $bad = cf5Call($this->admin, 'PATCH', '/employments/'.$this->ventas->id, ['custom_fields' => ['area_comercial' => 'Logística']]);
    $bad->assertStatus(422)->assertJsonPath('error.details.0.field', 'custom_fields.area_comercial');

    $ok = cf5Call($this->admin, 'PATCH', '/employments/'.$this->ventas->id, ['custom_fields' => ['area_comercial' => 'Ventas', 'bono_meta' => '1200.50']]);
    $ok->assertOk()->assertJsonPath('data.custom_fields.area_comercial', 'Ventas')->assertJsonPath('data.custom_fields.bono_meta', '1200.50');
    $this->assertMatchesOpenApi($ok, 'patch', '/employments/{id}', 200);
    cf5Call($this->admin, 'PATCH', '/employments/'.$this->planta->id, ['custom_fields' => ['area_comercial' => 'Planta', 'bono_meta' => '300']])->assertOk();

    // El cambio queda en la auditoría con el valor anterior y el nuevo.
    $audit = TenancyScope::runAs(CF5_TENANT, fn () => DB::table('audit_events')->where('action', 'employment.updated')->where('entity_id', $this->ventas->id)->orderByDesc('occurred_at')->first());
    expect((string) $audit->after_data)->toContain('area_comercial');
});

test('la tabla filtra y muestra el campo como columna; un sensible sin permiso no viaja ni se puede filtrar', function (): void {
    cf5Call($this->admin, 'PATCH', '/employments/'.$this->ventas->id, ['custom_fields' => ['area_comercial' => 'Ventas', 'bono_meta' => '1200']])->assertOk();
    cf5Call($this->admin, 'PATCH', '/employments/'.$this->planta->id, ['custom_fields' => ['area_comercial' => 'Planta', 'bono_meta' => '300']])->assertOk();

    $list = cf5Call($this->admin, 'GET', '/employments?filter[custom.area_comercial]=Ventas');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/employments', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$this->ventas->id])
        ->and($list->json('data.0.custom_fields.area_comercial'))->toBe('Ventas');

    $range = cf5Call($this->admin, 'GET', '/employments?filter[custom.bono_meta]=500..');
    expect(collect($range->json('data'))->pluck('id')->all())->toBe([$this->ventas->id]);

    // RRHH sin permiso de sueldos: el bono no viaja y filtrar por él es 400.
    $hr = cf5Call($this->rrhh, 'GET', '/employments');
    $hr->assertOk();
    expect($hr->json('data.0.custom_fields'))->not->toHaveKey('bono_meta');
    cf5Call($this->rrhh, 'GET', '/employments?filter[custom.bono_meta]=500..')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    cf5Call($this->admin, 'GET', '/employments?filter[custom.no_existe]=x')->assertStatus(400);

    // Regla 7: leer el sensible en la tabla deja registro de acceso.
    $logged = TenancyScope::runAs(CF5_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'employment')->where('entity_id', $this->ventas->id)->where('fields', 'like', '%custom.bono_meta%')->exists());
    expect($logged)->toBeTrue();
});

test('la exportación lleva las columnas del campo y respeta el filtro y el permiso por campo', function (): void {
    cf5Call($this->admin, 'PATCH', '/employments/'.$this->ventas->id, ['custom_fields' => ['area_comercial' => 'Ventas', 'bono_meta' => '1200']])->assertOk();
    cf5Call($this->admin, 'PATCH', '/employments/'.$this->planta->id, ['custom_fields' => ['area_comercial' => 'Planta']])->assertOk();

    $export = cf5Call($this->admin, 'POST', '/exports', ['resource' => 'employments', 'format' => 'csv', 'filters' => ['custom.area_comercial' => 'Ventas']]);
    $export->assertCreated();
    $csv = (string) cf5Call($this->admin, 'GET', '/exports/'.$export->json('data.id').'/download')->getContent();
    expect($csv)->toContain('custom.area_comercial')->toContain('custom.bono_meta')->toContain('1200')->not->toContain('Planta');

    $hr = cf5Call($this->rrhh, 'POST', '/exports', ['resource' => 'employments', 'format' => 'csv', 'filters' => []]);
    $hr->assertCreated();
    $hrCsv = (string) cf5Call($this->rrhh, 'GET', '/exports/'.$hr->json('data.id').'/download')->getContent();
    expect($hrCsv)->toContain('custom.area_comercial')->not->toContain('custom.bono_meta');
    cf5Call($this->rrhh, 'POST', '/exports', ['resource' => 'employments', 'format' => 'csv', 'filters' => ['custom.bono_meta' => '1..']])->assertStatus(422);
});

test('el importador común suma la columna del campo, la valida con su tipo y la guarda; no lo exige si la fila no lo trae', function (): void {
    TenancyScope::runAs(CF5_TENANT, function (): void {
        DB::table('custom_field_definitions')->insert(['tenant_id' => CF5_TENANT, 'entity_type' => 'legal_entity', 'key' => 'zona', 'label' => 'Zona', 'data_type' => 'select', 'options' => '["Norte","Sur"]', 'sort_order' => 1]);
        $importer = app(ImportRegistry::class)->get('legal_entities');
        $context = new ImportContext(CF5_TENANT, (string) Str::uuid(), $this->admin->id, 'es');
        expect(collect($importer->columns())->pluck('key')->all())->toContain('custom.zona');

        $row = ['name' => 'Importada S.A.C.', 'tax_id' => '20601234561', 'country' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'payroll_regime' => 'pe_general'];
        $bad = $importer->validate($row + ['custom.zona' => 'Oeste'], $context);
        expect($bad->ok())->toBeFalse()->and($bad->errors[0]['column'] ?? null)->toBe('custom.zona');

        $check = $importer->validate($row + ['custom.zona' => 'Sur'], $context);
        expect($check->ok())->toBeTrue();
        $result = $importer->import($check->parsed, $context);
        $value = DB::table('custom_field_values')->where('entity_type', 'legal_entity')->where('entity_id', $result['entity_id'])->value('value');
        expect(json_decode((string) $value, true))->toBe('Sur');
    });
});
