<?php

/**
 * COL-12 · Inicio de RRHH · MSS-1 · Inicio del manager · MSS-2 · Mi equipo.
 *
 * Estructura: Marta (jefa, posición JEF) ← Juan (directo, por manager_employment_id) ← Lucia (indirecta, reporta
 * a Juan). Pedro está en la misma empresa pero fuera del equipo. Hoy (congelado): 13 de noviembre de 2026.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const HOME_TENANT = 'aaaaaaaa-0c12-4000-8000-00000000000a';
const HOME_OTHER = 'bbbbbbbb-0c12-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-13 12:00:00');
    TenancyScope::clear();
    foreach ([HOME_TENANT => 'home-org', HOME_OTHER => 'home-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }

    $this->org = TenancyScope::runAs(HOME_TENANT, function (): array {
        $entity = (string) LegalEntity::factory()->create(['timezone' => 'America/Lima'])->id;
        $unit = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => HOME_TENANT, 'code' => 'OPE', 'name' => 'Operaciones']);
        $site = (string) Str::uuid();
        DB::table('sites')->insert(['id' => $site, 'tenant_id' => HOME_TENANT, 'legal_entity_id' => $entity, 'code' => 'LIM', 'name' => 'Lima', 'timezone' => 'America/Lima']);
        $profile = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => HOME_TENANT, 'code' => 'ANA', 'name' => 'Analista']);
        $pos = function (string $code, string $status, ?string $reportsTo) use ($entity, $unit, $profile): string {
            $id = (string) Str::uuid();
            DB::table('positions')->insert(['id' => $id, 'tenant_id' => HOME_TENANT, 'code' => $code, 'title' => 'Puesto '.$code, 'job_profile_id' => $profile,
                'org_unit_id' => $unit, 'legal_entity_id' => $entity, 'status' => $status, 'reports_to_position_id' => $reportsTo]);

            return $id;
        };
        $bossPosition = $pos('JEF', 'filled', null);
        $vacant = $pos('VAC', 'open', $bossPosition);
        $pos('OTR', 'open', null);

        $mk = function (string $name, ?string $manager, ?string $position, string $hire = '2025-01-01', ?string $birth = null) use ($entity, $unit, $profile, $site): array {
            $person = (string) Str::uuid();
            $employment = (string) Str::uuid();
            DB::table('people')->insert(['id' => $person, 'tenant_id' => HOME_TENANT, 'first_names' => $name, 'paternal_surname' => 'Prueba', 'birth_date' => $birth]);
            DB::table('employments')->insert(['id' => $employment, 'tenant_id' => HOME_TENANT, 'person_id' => $person, 'legal_entity_id' => $entity, 'employee_code' => strtoupper($name), 'hire_date' => $hire, 'status' => 'active']);
            DB::table('employment_assignments')->insert([
                'tenant_id' => HOME_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit, 'site_id' => $site,
                'position_id' => $position, 'manager_employment_id' => $manager, 'valid_from' => $hire,
            ]);
            DB::table('employment_compensations')->insert(['tenant_id' => HOME_TENANT, 'employment_id' => $employment, 'base_salary' => '3000.00', 'currency' => 'PEN', 'valid_from' => $hire]);

            return [$person, $employment];
        };
        [$bossPerson, $boss] = $mk('Marta', null, $bossPosition);
        [, $juan] = $mk('Juan', $boss, null, '2025-01-01', '1990-11-20');
        [, $lucia] = $mk('Lucia', $juan, null, '2026-11-02');
        [, $pedro] = $mk('Pedro', null, null, '2026-11-05', '1991-11-15');

        // Contrato de Juan que vence en 10 días.
        $type = (string) Str::uuid();
        DB::table('contract_types')->insert(['id' => $type, 'tenant_id' => HOME_TENANT, 'code' => 'PLZ', 'name' => 'Plazo fijo', 'category' => 'fixed_term']);
        DB::table('contracts')->insert(['tenant_id' => HOME_TENANT, 'employment_id' => $juan, 'contract_type_id' => $type, 'valid_from' => '2026-01-01', 'valid_to' => '2026-11-23', 'status' => 'active']);
        DB::table('contracts')->insert(['tenant_id' => HOME_TENANT, 'employment_id' => $pedro, 'contract_type_id' => $type, 'valid_from' => '2026-11-05', 'valid_to' => '2026-11-30', 'status' => 'active']);

        return compact('entity', 'bossPerson', 'boss', 'juan', 'lucia', 'pedro', 'vacant');
    });

    $this->hr = homeUser(HOME_TENANT, 'rrhh');
    $this->manager = homeUser(HOME_TENANT, 'manager', $this->org['bossPerson']);
    $this->employee = homeUser(HOME_TENANT, 'empleado');
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function homeUser(string $tenant, string $role, ?string $personId = null): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $personId): User {
        $u = User::factory()->create(['person_id' => $personId]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function homeCall(User $user, string $path): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id])->getJson('/api/v1'.$path);
}

/**
 * @return array<string, array<string, mixed>>
 */
function homeCards(TestResponse $response): array
{
    return collect($response->json('data.cards'))->keyBy('key')->all();
}

function homeModuleOf(string $permission): string
{
    return (string) DB::table('permissions')->where('code', $permission)->value('module_code');
}

test('COL-12 · el tablero de RRHH calcula en el servidor con su alcance y cada tarjeta enlaza a la lista filtrada', function (): void {
    $before = TenancyScope::runAs(HOME_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'home_card')->count());

    $res = homeCall($this->hr, '/home/hr');
    $res->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/home/hr', 200);
    $cards = homeCards($res);

    expect($res->json('data.audience'))->toBe('hr')
        ->and($cards['headcount']['value'])->toBe(4)
        ->and($cards['hires_month']['value'])->toBe(2)
        ->and($cards['hires_month']['link'])->toBe('/personas/colaboradores?filter[hired_from]=2026-11-01&filter[hired_to]=2026-11-30')
        ->and($cards['contracts_expiring']['value'])->toBe(2)
        ->and($cards['vacant_positions']['value'])->toBe(2)
        ->and($cards['labor_cost']['meta']['amounts'])->toBe([['amount' => '12000.00', 'currency' => 'PEN']]);

    // Regla 7: sueldos agregados y cumpleaños dejan su fila en el registro de accesos.
    $after = TenancyScope::runAs(HOME_TENANT, fn () => DB::table('data_access_logs')->where('entity_type', 'home_card')->pluck('data_category')->all());
    expect(count($after) - $before)->toBeGreaterThanOrEqual(2)->and($after)->toContain('salary');

    // El enlace de ingresos funciona sobre la lista de colaboradores.
    $list = homeCall($this->hr, '/employments?filter[hired_from]=2026-11-01&filter[hired_to]=2026-11-30');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/employments', 200);
    expect(collect($list->json('data'))->pluck('person_name')->sort()->values()->all())->toBe(['Lucia Prueba', 'Pedro Prueba']);
    homeCall($this->hr, '/employments?filter[hired_from]=noviembre')->assertStatus(400);
});

test('COL-12 · el costo laboral exige el permiso de sueldo y las tarjetas de módulos no contratados no viajan', function (): void {
    $noSalary = TenancyScope::runAs(HOME_TENANT, fn () => IdentityFixtures::userWithPermissions(HOME_TENANT, ['people.employments.view', 'contracts.contracts.view', 'time.vacation_requests.view']));
    $res = homeCall($noSalary, '/home/hr');
    $res->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/home/hr', 200);
    $cards = homeCards($res);
    expect($cards)->toHaveKey('headcount')->not->toHaveKey('labor_cost')->not->toHaveKey('birthdays')
        ->and(json_encode($res->json()))->not->toContain('12000');

    IdentityFixtures::disableModule(HOME_TENANT, homeModuleOf('time.vacation_requests.view'));
    $off = homeCards(homeCall($this->hr, '/home/hr'));
    expect($off)->not->toHaveKey('on_vacation')->toHaveKey('headcount');
});

test('MSS-1 · el manager ve solo a sus reportes directos e indirectos', function (): void {
    $res = homeCall($this->manager, '/home/manager');
    $res->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/home/manager', 200);
    $cards = homeCards($res);

    expect($res->json('meta'))->toMatchArray(['team_size' => 2, 'direct' => 1])
        ->and($cards['headcount']['value'])->toBe(2)
        ->and($cards['headcount']['link'])->toBe('/mi-equipo')
        ->and($cards['contracts_expiring']['value'])->toBe(1)
        ->and(collect($cards['contracts_expiring']['items'])->pluck('name')->all())->toBe(['Juan Prueba'])
        ->and($cards['vacant_positions']['value'])->toBe(1)
        ->and($cards)->not->toHaveKey('labor_cost')->not->toHaveKey('hires_month')
        ->and(json_encode($res->json()))->not->toContain('Pedro');

    // Sin people.team.view_indirect solo cuenta a los directos.
    $direct = TenancyScope::runAs(HOME_TENANT, function (): User {
        $u = IdentityFixtures::userWithPermissions(HOME_TENANT, ['people.team.view', 'people.employments.view'], 'team');
        User::query()->whereKey($this->manager->id)->update(['person_id' => null]);
        $u->forceFill(['person_id' => $this->org['bossPerson']])->save();

        return $u;
    });
    $only = homeCall($direct, '/home/manager');
    $only->assertOk();
    expect($only->json('meta.team_size'))->toBe(1)->and(homeCards($only)['headcount']['value'])->toBe(1);
});

test('COL-12 · MSS-1 · /home ofrece la vista según el rol', function (): void {
    $hr = homeCall($this->hr, '/home');
    $hr->assertOk();
    $this->assertMatchesOpenApi($hr, 'get', '/home', 200);
    expect($hr->json('data'))->toBe(['views' => ['hr', 'employee'], 'default' => 'hr']);

    expect(homeCall($this->manager, '/home')->json('data'))->toBe(['views' => ['manager', 'employee'], 'default' => 'manager'])
        ->and(homeCall($this->employee, '/home')->json('data'))->toBe(['views' => ['employee'], 'default' => 'employee']);
});

test('MSS-2 · Mi equipo lista directos e indirectos, con sueldo solo con permiso de campo y acciones sobre el subordinado', function (): void {
    $res = homeCall($this->manager, '/my-team');
    $res->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/my-team', 200);
    $rows = collect($res->json('data'))->keyBy('person_name');
    expect($rows->keys()->sort()->values()->all())->toBe(['Juan Prueba', 'Lucia Prueba'])
        ->and($rows['Juan Prueba'])->toMatchArray(['level' => 1, 'is_direct' => true, 'today_status' => 'present'])
        ->and($rows['Lucia Prueba'])->toMatchArray(['level' => 2, 'is_direct' => false])
        ->and($rows['Juan Prueba'])->not->toHaveKey('base_salary')
        ->and($rows['Juan Prueba']['team_actions'])->toContain('propose_movement')
        ->and($res->json('meta'))->toMatchArray(['total' => 2, 'direct' => 1, 'includes_indirect' => true])
        ->and(collect($res->json('meta.vacant_positions'))->pluck('code')->all())->toBe(['VAC']);

    $direct = homeCall($this->manager, '/my-team?filter[level]=direct');
    expect(collect($direct->json('data'))->pluck('person_name')->all())->toBe(['Juan Prueba']);
    homeCall($this->manager, '/my-team?filter[level]=todos')->assertStatus(400);

    // Con el permiso de campo el sueldo viaja y la lectura queda registrada (reglas 6 y 7).
    $withSalary = TenancyScope::runAs(HOME_TENANT, function (): User {
        $u = IdentityFixtures::userWithPermissions(HOME_TENANT, ['people.team.view', 'people.employments.salary.view'], 'team');
        User::query()->whereKey($this->manager->id)->update(['person_id' => null]);
        $u->forceFill(['person_id' => $this->org['bossPerson']])->save();

        return $u;
    });
    $paid = homeCall($withSalary, '/my-team');
    $paid->assertOk();
    $this->assertMatchesOpenApi($paid, 'get', '/my-team', 200);
    expect($paid->json('data.0.base_salary'))->toBe(['amount' => '3000.00', 'currency' => 'PEN'])
        ->and($paid->json('meta.total'))->toBe(1);
    $logged = TenancyScope::runAs(HOME_TENANT, fn () => DB::table('data_access_logs')->where('user_id', $withSalary->id)->where('data_category', 'salary')->count());
    expect($logged)->toBe(1);
});

test('COL-12 · MSS-1 · MSS-2 · permisos, módulo y aislamiento', function (): void {
    homeCall($this->employee, '/home/hr')->assertForbidden();
    homeCall($this->employee, '/home/manager')->assertForbidden();
    $this->assertMatchesOpenApi(homeCall($this->employee, '/my-team'), 'get', '/my-team', 403);

    IdentityFixtures::disableModule(HOME_TENANT, homeModuleOf('people.team.view'));
    homeCall($this->manager, '/my-team')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
    TenancyScope::runAs(HOME_TENANT, fn () => DB::table('tenant_modules')->update(['status' => 'active']));

    // Un usuario de otra organización no ve a nadie de la primera.
    $outsider = homeUser(HOME_OTHER, 'rrhh');
    $other = homeCall($outsider, '/home/hr');
    $other->assertOk();
    expect(homeCards($other)['headcount']['value'])->toBe(0);
    expect(homeCall($outsider, '/my-team')->json('data'))->toBe([]);
});
