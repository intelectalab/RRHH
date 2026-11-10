<?php

/**
 * SEG-10 · Alcance de datos · SEG-9 · Permisos por campo sensible.
 *
 * Estructura de prueba (una organización, dos empresas, dos sedes):
 *
 *   Gerencia (unidad G)                     Sede Lima (empresa E1)
 *    └─ Ventas (unidad V)                   Sede Arequipa (empresa E2)
 *
 *   Posiciones:  P_GER (Gerente) ← P_JV (Jefe de ventas) ← P_V1 (Vendedor 1)
 *                                                       ← P_V2 (Vendedor 2, Arequipa)
 *   Relaciones:  Ana = Gerente, Beto = Jefe de ventas, Caro = Vendedor 1,
 *                Dani = Vendedor 2 (otra empresa y sede), Eva = sin jefe por posición
 *                pero con jefe directo Beto (excepción por relación).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const SCO_TENANT = 'aaaaaaaa-1010-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => SCO_TENANT, 'slug' => 'sco-org', 'name' => 'Scope Org', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [SCO_TENANT]);

    $this->org = TenancyScope::runAs(SCO_TENANT, function (): array {
        $id = fn () => (string) Str::uuid();
        $e1 = LegalEntity::factory()->create(['tax_id' => '20101010101'])->id;
        $e2 = LegalEntity::factory()->create(['tax_id' => '20202020202'])->id;
        $lima = $id();
        $aqp = $id();
        DB::table('sites')->insert([
            ['id' => $lima, 'tenant_id' => SCO_TENANT, 'legal_entity_id' => $e1, 'code' => 'LIM', 'name' => 'Lima'],
            ['id' => $aqp, 'tenant_id' => SCO_TENANT, 'legal_entity_id' => $e2, 'code' => 'AQP', 'name' => 'Arequipa'],
        ]);
        $g = $id();
        $v = $id();
        DB::table('org_units')->insert([
            ['id' => $g, 'tenant_id' => SCO_TENANT, 'parent_id' => null, 'code' => 'G', 'name' => 'Gerencia', 'kind' => 'management'],
            ['id' => $v, 'tenant_id' => SCO_TENANT, 'parent_id' => $g, 'code' => 'V', 'name' => 'Ventas', 'kind' => 'area'],
        ]);
        $jp = $id();
        DB::table('job_profiles')->insert(['id' => $jp, 'tenant_id' => SCO_TENANT, 'code' => 'GEN', 'name' => 'Genérico']);

        $pos = ['GER' => $id(), 'JV' => $id(), 'V1' => $id(), 'V2' => $id(), 'X' => $id()];
        $mkPos = fn (string $k, ?string $reportsTo, string $unit, string $le) => DB::table('positions')->insert([
            'id' => $pos[$k], 'tenant_id' => SCO_TENANT, 'code' => $k, 'title' => $k, 'job_profile_id' => $jp,
            'org_unit_id' => $unit, 'legal_entity_id' => $le, 'reports_to_position_id' => $reportsTo, 'status' => 'filled',
        ]);
        $mkPos('GER', null, $g, $e1);
        $mkPos('JV', $pos['GER'], $v, $e1);
        $mkPos('V1', $pos['JV'], $v, $e1);
        $mkPos('V2', $pos['JV'], $v, $e2);
        $mkPos('X', null, $g, $e1);

        $people = [];
        $emps = [];
        $mkEmp = function (string $name, string $le, string $posKey, string $unit, string $site, ?string $manager = null) use (&$people, &$emps, $id, $jp, $pos): void {
            $people[$name] = $id();
            $emps[$name] = $id();
            DB::table('people')->insert(['id' => $people[$name], 'tenant_id' => SCO_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba']);
            DB::table('employments')->insert(['id' => $emps[$name], 'tenant_id' => SCO_TENANT, 'person_id' => $people[$name], 'legal_entity_id' => $le, 'employee_code' => $name, 'hire_date' => '2024-01-01', 'status' => 'active']);
            DB::table('employment_assignments')->insert([
                'tenant_id' => SCO_TENANT, 'employment_id' => $emps[$name], 'job_profile_id' => $jp, 'position_id' => $pos[$posKey],
                'org_unit_id' => $unit, 'site_id' => $site, 'manager_employment_id' => $manager, 'valid_from' => '2024-01-01',
            ]);
        };
        $mkEmp('Ana', $e1, 'GER', $g, $lima);
        $mkEmp('Beto', $e1, 'JV', $v, $lima);
        $mkEmp('Caro', $e1, 'V1', $v, $lima);
        $mkEmp('Dani', $e2, 'V2', $v, $aqp);
        $mkEmp('Eva', $e1, 'X', $g, $lima, $emps['Beto']);

        return compact('e1', 'e2', 'lima', 'aqp', 'g', 'v', 'people', 'emps');
    });

    Route::middleware(['api', 'auth:ayni', 'permission:people.employments.view'])->get('/api/v1/__employments-probe', function (Request $request) {
        $query = DB::table('employments')->select('employee_code');
        app(DataScope::class)->applyEmployment($query, $request, 'people.employments.view', 'employments.id');

        return response()->json(['data' => $query->orderBy('employee_code')->pluck('employee_code')]);
    });
});

afterEach(function (): void {
    TenancyScope::clear();
});

function scoUser(string $systemRole, ?string $scope = null, array $scopes = [], ?string $personId = null): User
{
    return TenancyScope::runAs(SCO_TENANT, function () use ($systemRole, $scope, $scopes, $personId): User {
        $user = User::factory()->create(['person_id' => $personId]);
        $role = Role::query()->where('system_role_code', $systemRole)->firstOrFail();
        $assignment = $user->roleAssignments()->create(['role_id' => $role->id, 'scope_override' => $scope]);
        foreach ($scopes as $column => $value) {
            DB::table('user_role_scopes')->insert(['tenant_id' => SCO_TENANT, 'user_role_id' => $assignment->id, $column => $value]);
        }

        return $user;
    });
}

function scoVisible(User $user): array
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(DataScope::class)->forget();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])
        ->getJson('/api/v1/__employments-probe')->assertOk()->json('data');
}

test('RRHH con alcance de organización ve a todos', function (): void {
    expect(scoVisible(scoUser('rrhh')))->toBe(['Ana', 'Beto', 'Caro', 'Dani', 'Eva']);
});

test('un manager solo ve a quienes le reportan directa o indirectamente (incluida la excepción por relación)', function (): void {
    expect(scoVisible(scoUser('manager', 'team', [], $this->org['people']['Beto'])))->toBe(['Caro', 'Dani', 'Eva'])
        ->and(scoVisible(scoUser('manager', 'team', [], $this->org['people']['Ana'])))->toBe(['Beto', 'Caro', 'Dani', 'Eva'])
        ->and(scoVisible(scoUser('manager', 'team', [], $this->org['people']['Caro'])))->toBe([]);
});

test('el alcance se recalcula cuando cambia el jefe', function (): void {
    $beto = scoUser('manager', 'team', [], $this->org['people']['Beto']);
    expect(scoVisible($beto))->toContain('Caro');

    // Caro pasa a reportar a Ana por excepción: Beto deja de verla.
    TenancyScope::runAs(SCO_TENANT, fn () => DB::table('employment_assignments')->where('employment_id', $this->org['emps']['Caro'])
        ->update(['manager_employment_id' => $this->org['emps']['Ana']]));

    expect(scoVisible($beto))->not->toContain('Caro');
});

test('RRHH con alcance de sede no ve colaboradores de otra sede', function (): void {
    expect(scoVisible(scoUser('rrhh', 'site', ['site_id' => $this->org['aqp']])))->toBe(['Dani']);
});

test('alcance de empresa y de unidad (con descendientes)', function (): void {
    expect(scoVisible(scoUser('rrhh', 'legal_entity', ['legal_entity_id' => $this->org['e2']])))->toBe(['Dani'])
        ->and(scoVisible(scoUser('rrhh', 'org_unit', ['org_unit_id' => $this->org['v']])))->toBe(['Beto', 'Caro', 'Dani'])
        ->and(scoVisible(scoUser('rrhh', 'org_unit', ['org_unit_id' => $this->org['g']])))->toBe(['Ana', 'Beto', 'Caro', 'Dani', 'Eva']);
});

test('el empleado solo ve lo propio', function (): void {
    expect(scoVisible(scoUser('empleado', 'own', [], $this->org['people']['Caro'])))->toBe(['Caro']);
});

/*
 * SEG-9 · Permisos por campo sensible.
 */

test('se puede crear un administrador que gestiona colaboradores y no ve sueldos', function (): void {
    $noSalary = IdentityFixtures::userWithPermissions(SCO_TENANT, ['people.employments.view', 'people.employments.update']);
    $withSalary = IdentityFixtures::userWithPermissions(SCO_TENANT, ['people.employments.view', 'people.employments.salary.view']);

    Route::middleware(['api', 'auth:ayni', 'permission:people.employments.view'])->get('/api/v1/__salary-probe', function (Request $request) {
        $rows = [['id' => (string) Str::uuid(), 'employee_code' => 'Caro', 'base_salary' => ['amount' => '3500.00', 'currency' => 'PEN']]];

        return response()->json(['data' => app(SensitiveFields::class)->presentMany('Employment', $rows, $request->attributes->get('effective_permissions'))]);
    });

    $call = function (User $u) {
        TenancyScope::clear();
        app('auth')->forgetGuards();

        return test()->actingAs($u, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])->getJson('/api/v1/__salary-probe');
    };

    $hidden = $call($noSalary)->assertOk();
    expect($hidden->json('data.0'))->not->toHaveKey('base_salary')
        ->and((string) $hidden->getContent())->not->toContain('3500');

    $visible = $call($withSalary)->assertOk();
    expect($visible->json('data.0.base_salary.amount'))->toBe('3500.00');

    // Regla 7: la lectura del sueldo quedó registrada; la omisión no.
    TenancyScope::runAs(SCO_TENANT, function () use ($withSalary, $noSalary): void {
        $log = DB::table('data_access_logs')->where('user_id', $withSalary->id)->sole();
        expect($log->data_category)->toBe('salary')->and($log->fields)->toBe('{base_salary}')->and($log->entity_type)->toBe('Employment')
            ->and(DB::table('data_access_logs')->where('user_id', $noSalary->id)->count())->toBe(0);
    });
});

test('sin el permiso de campo no se filtra ni se ordena por él', function (): void {
    $noSalary = IdentityFixtures::userWithPermissions(SCO_TENANT, ['people.employments.view']);
    Route::middleware(['api', 'auth:ayni', 'permission:people.employments.view'])->get('/api/v1/__salary-filter', function (Request $request) {
        app(SensitiveFields::class)->guardQuery('Employment', $request, $request->attributes->get('effective_permissions'));

        return response()->json(['data' => []]);
    });

    TenancyScope::clear();
    $this->actingAs($noSalary, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])
        ->getJson('/api/v1/__salary-filter?sort=-base_salary')->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    TenancyScope::clear();
    $this->actingAs($noSalary, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])
        ->getJson('/api/v1/__salary-filter?filter[base_salary]=3500')->assertStatus(400);
    TenancyScope::clear();
    $this->actingAs($noSalary, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])
        ->getJson('/api/v1/__salary-filter?sort=employee_code')->assertOk();
});

test('todo campo sensible del contrato tiene permiso de lectura declarado y existente', function (): void {
    $spec = test()->loadOpenApiSpec();
    $catalog = DB::table('permissions')->pluck('code')->all();
    $problems = [];

    foreach ($spec['components']['schemas'] as $name => $schema) {
        foreach ((array) ($schema['x-sensitive-fields'] ?? []) as $field) {
            $entry = config("sensitive_fields.{$name}.{$field}");
            if (! is_array($entry)) {
                $problems[] = "{$name}.{$field}: sin permiso en config/sensitive_fields.php";
            } elseif (! in_array($entry[0], $catalog, true)) {
                $problems[] = "{$name}.{$field}: el permiso {$entry[0]} no existe";
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

test('el administrador asigna un rol con alcance de sede y el alcance aplica de inmediato', function (): void {
    $admin = scoUser('admin_empresa');
    $target = TenancyScope::runAs(SCO_TENANT, fn () => User::factory()->create());
    $rrhh = TenancyScope::runAs(SCO_TENANT, fn () => (string) Role::query()->where('system_role_code', 'rrhh')->value('id'));

    TenancyScope::clear();
    app('auth')->forgetGuards();
    $response = $this->actingAs($admin, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])
        ->patchJson('/api/v1/users/'.$target->id, ['role_assignments' => [['role_id' => $rrhh, 'scope' => 'site', 'site_ids' => [$this->org['lima']]]]]);
    $response->assertOk()
        ->assertJsonPath('data.role_assignments.0.scope', 'site')
        ->assertJsonPath('data.role_assignments.0.site_ids', [$this->org['lima']]);
    $this->assertMatchesOpenApi($response, 'patch', '/users/{id}', 200);

    expect(scoVisible($target))->toBe(['Ana', 'Beto', 'Caro', 'Eva']);

    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->actingAs($admin, 'ayni')->withHeaders(['X-Tenant-Id' => SCO_TENANT])
        ->patchJson('/api/v1/users/'.$target->id, ['role_assignments' => [['role_id' => $rrhh, 'scope' => 'site', 'site_ids' => []]]])
        ->assertStatus(422);
});
