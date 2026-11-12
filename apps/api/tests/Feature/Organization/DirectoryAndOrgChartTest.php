<?php

/**
 * ORG-6 · Organigrama a una fecha (por unidades y por línea de reporte) y ORG-7 · Directorio de
 * personas: solo nombre, puesto, unidad, sede, empresa y el contacto laboral que la organización
 * publica (RN-ORG-08); el jefe se recalcula solo cuando cambia la asignación o el ocupante (RN-ORG-03).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const DIR_TENANT = 'aaaaaaaa-0e06-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-12 10:00:00');
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => DIR_TENANT, 'slug' => 'dir-org', 'name' => 'Grupo Directorio', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [DIR_TENANT]);
    TenancyScope::runAs(DIR_TENANT, fn () => IdentityFixtures::enableAllModules());
    $this->admin = dirUser('admin_empresa');

    // Estructura: Gerencia General > Finanzas > Tesorería; Operaciones al lado.
    $this->company = TenancyScope::runAs(DIR_TENANT, fn () => LegalEntity::factory()->create()->id);
    $this->site = TenancyScope::runAs(DIR_TENANT, fn () => Site::factory()->create(['name' => 'Sede Lima'])->id);
    $this->job = TenancyScope::runAs(DIR_TENANT, fn () => JobProfile::factory()->create(['name' => 'Analista contable'])->id);
    $this->boss = TenancyScope::runAs(DIR_TENANT, fn () => JobProfile::factory()->create(['name' => 'Gerente de finanzas'])->id);
    $gg = dirUnit('GG', 'Gerencia General');
    $this->fin = dirUnit('FIN', 'Finanzas', $gg);
    $this->tes = dirUnit('TES', 'Tesorería', $this->fin);
    $this->ops = dirUnit('OPS', 'Operaciones', $gg);
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function dirUser(string $role): User
{
    return TenancyScope::runAs(DIR_TENANT, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function dirCall(User $user, string $path)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id])->getJson('/api/v1'.$path);
}

function dirUnit(string $code, string $name, ?string $parent = null): string
{
    $admin = test()->admin;
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    $types = test()->actingAs($admin, 'ayni')->withHeaders(['X-Tenant-Id' => DIR_TENANT])->getJson('/api/v1/org-unit-types')->json('data');
    $type = collect($types)->firstWhere('code', $parent === null ? 'management' : 'area')['id'] ?? $types[0]['id'];
    $r = test()->actingAs($admin, 'ayni')->withHeaders(['X-Tenant-Id' => DIR_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/org-units', ['code' => $code, 'name' => $name, 'type_id' => $type, 'parent_id' => $parent, 'effective_from' => '2025-01-01']);
    $r->assertCreated();

    return (string) $r->json('data.id');
}

/**
 * Persona + relación laboral + asignación (sembradas en base: el módulo de colaboradores aún no existe).
 *
 * @param  array<string, mixed>  $assignment
 * @param  array<string, mixed>  $person
 * @param  array<string, mixed>  $employment
 */
function dirPerson(string $first, string $last, string $unit, array $assignment = [], array $person = [], array $employment = []): string
{
    return TenancyScope::runAs(DIR_TENANT, function () use ($first, $last, $unit, $assignment, $person, $employment): string {
        $p = (string) Str::uuid();
        $e = (string) Str::uuid();
        DB::table('people')->insert(array_merge(['id' => $p, 'tenant_id' => DIR_TENANT, 'first_names' => $first, 'paternal_surname' => $last], $person));
        DB::table('employments')->insert(array_merge([
            'id' => $e, 'tenant_id' => DIR_TENANT, 'person_id' => $p, 'legal_entity_id' => test()->company,
            'employee_code' => 'E'.Str::random(6), 'hire_date' => '2025-01-01', 'status' => 'active',
        ], $employment));
        DB::table('employment_assignments')->insert(array_merge([
            'id' => (string) Str::uuid(), 'tenant_id' => DIR_TENANT, 'employment_id' => $e, 'job_profile_id' => test()->job,
            'org_unit_id' => $unit, 'site_id' => test()->site, 'valid_from' => '2025-01-01',
        ], $assignment));

        return $e;
    });
}

/** Cambia el jefe desde una fecha: cierra la asignación vigente e inserta otra (C-03). */
function dirChangeManager(string $employment, ?string $manager, string $from): void
{
    TenancyScope::runAs(DIR_TENANT, function () use ($employment, $manager, $from): void {
        $current = DB::table('employment_assignments')->where('employment_id', $employment)->whereNull('valid_to')->first();
        DB::table('employment_assignments')->where('id', $current->id)->update(['valid_to' => Carbon::parse($from)->subDay()->toDateString()]);
        DB::table('employment_assignments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => DIR_TENANT, 'employment_id' => $employment, 'job_profile_id' => $current->job_profile_id,
            'org_unit_id' => $current->org_unit_id, 'site_id' => $current->site_id, 'manager_employment_id' => $manager, 'valid_from' => $from,
        ]);
    });
}

it('el directorio muestra nombre, puesto, unidad, sede y el contacto laboral publicado; nunca datos personales ni sensibles', function (): void {
    dirPerson('Ana', 'Quispe', $this->fin, [], [
        'personal_email' => 'ana.personal@correo.test', 'mobile_phone' => '999888777', 'birth_date' => '1990-05-01', 'marital_status' => 'married',
    ], ['work_email' => 'ana@empresa.test', 'work_phone' => '014445555', 'work_phone_ext' => '204']);

    $r = dirCall($this->admin, '/directory');
    $this->assertMatchesOpenApi($r, 'get', '/directory', 200);
    $row = $r->json('data.0');
    expect($row['display_name'])->toBe('Ana Quispe')
        ->and($row['job_title'])->toBe('Analista contable')
        ->and($row['org_unit']['name'])->toBe('Finanzas')
        ->and($row['site']['name'])->toBe('Sede Lima')
        ->and($row['contact'])->toBe(['work_email' => 'ana@empresa.test', 'work_phone' => '014445555'])
        ->and(array_keys($row))->toEqualCanonicalizing(['employment_id', 'display_name', 'job_profile_id', 'job_title', 'org_unit', 'site', 'legal_entity', 'manager_employment_id', 'contact'])
        ->and($r->json('meta.total'))->toBe(1)
        ->and($r->json('meta.contact_fields'))->toBe(['work_email', 'work_phone']);
    $body = $r->getContent();
    foreach (['ana.personal@correo.test', '999888777', '1990-05-01', 'married', 'employee_code'] as $secret) {
        expect($body)->not->toContain($secret);
    }
});

it('la organización decide qué datos de contacto ve todo el mundo', function (): void {
    dirPerson('Ana', 'Quispe', $this->fin, [], [], ['work_email' => 'ana@empresa.test', 'work_phone' => '014445555', 'work_phone_ext' => '204']);
    TenancyScope::runAs(DIR_TENANT, fn () => app(ParameterResolver::class)->set('directorio.campos_contacto', ['work_phone_ext'], 'organization', null, null, 'Solo anexos'));

    $r = dirCall(dirUser('empleado'), '/directory');
    $this->assertMatchesOpenApi($r, 'get', '/directory', 200);
    expect($r->json('data.0.contact'))->toBe(['work_phone_ext' => '204'])
        ->and($r->getContent())->not->toContain('ana@empresa.test');
});

it('un Empleado consulta el directorio de toda la organización, no solo su ficha', function (): void {
    dirPerson('Ana', 'Quispe', $this->fin);
    dirPerson('Bruno', 'Rojas', $this->ops);

    $r = dirCall(dirUser('empleado'), '/directory');
    $this->assertMatchesOpenApi($r, 'get', '/directory', 200);
    expect($r->json('meta.total'))->toBe(2);
});

it('busca por nombre y puesto y filtra por unidad (con subunidades), sede y puesto', function (): void {
    dirPerson('Ana', 'Quispe', $this->fin);
    dirPerson('Carla', 'Huamán', $this->tes, ['job_profile_id' => $this->boss]);
    dirPerson('Bruno', 'Rojas', $this->ops);

    $names = fn ($r) => collect($r->json('data'))->pluck('display_name')->all();
    expect($names(dirCall($this->admin, '/directory?q=quis')))->toBe(['Ana Quispe'])
        ->and($names(dirCall($this->admin, '/directory?q=gerente')))->toBe(['Carla Huamán'])
        ->and($names(dirCall($this->admin, '/directory?org_unit_id='.$this->fin)))->toEqualCanonicalizing(['Ana Quispe', 'Carla Huamán'])
        ->and($names(dirCall($this->admin, '/directory?job_profile_id='.$this->boss)))->toBe(['Carla Huamán'])
        ->and(dirCall($this->admin, '/directory?site_id='.$this->site)->json('meta.total'))->toBe(3)
        ->and(dirCall($this->admin, '/directory?per_page=2&page=2')->json('data'))->toHaveCount(1);

    $bad = dirCall($this->admin, '/directory?org_unit_id=no-es-un-id');
    $this->assertMatchesOpenApi($bad, 'get', '/directory', 400);
});

it('no muestra relaciones terminadas, pendientes ni personas anonimizadas', function (): void {
    dirPerson('Ana', 'Quispe', $this->fin);
    dirPerson('Pedro', 'Cesado', $this->fin, [], [], ['status' => 'terminated', 'termination_date' => '2026-06-30']);
    dirPerson('Pía', 'Pendiente', $this->fin, [], [], ['status' => 'pending']);
    dirPerson('Olga', 'Anónima', $this->fin, [], ['anonymized_at' => now()]);

    expect(collect(dirCall($this->admin, '/directory')->json('data'))->pluck('display_name')->all())->toBe(['Ana Quispe']);
});

it('la tarjeta de contacto trae jefe, compañeros y reportes directos', function (): void {
    $jefa = dirPerson('Julia', 'Mendoza', $this->fin, ['job_profile_id' => $this->boss]);
    $ana = dirPerson('Ana', 'Quispe', $this->fin, ['manager_employment_id' => $jefa]);
    dirPerson('Bruno', 'Rojas', $this->fin, ['manager_employment_id' => $jefa]);
    dirPerson('Diego', 'Soto', $this->tes, ['manager_employment_id' => $ana]);

    $r = dirCall($this->admin, '/directory/'.$ana);
    $this->assertMatchesOpenApi($r, 'get', '/directory/{employment_id}', 200);
    expect($r->json('data.manager.display_name'))->toBe('Julia Mendoza')
        ->and(collect($r->json('data.peers'))->pluck('display_name')->all())->toBe(['Bruno Rojas'])
        ->and(collect($r->json('data.direct_reports'))->pluck('display_name')->all())->toBe(['Diego Soto']);

    $this->assertMatchesOpenApi(dirCall($this->admin, '/directory/'.Str::uuid()), 'get', '/directory/{employment_id}', 404);
});

it('organigrama por personas: cambiar el jefe se refleja solo y una fecha pasada muestra la línea de entonces', function (): void {
    $jefa = dirPerson('Julia', 'Mendoza', $this->fin);
    $otro = dirPerson('Óscar', 'Paredes', $this->ops);
    $ana = dirPerson('Ana', 'Quispe', $this->fin, ['manager_employment_id' => $jefa]);

    $parent = fn (string $path) => collect(dirCall($this->admin, $path)->json('data'))->firstWhere('id', $ana)['parent_id'];
    $r = dirCall($this->admin, '/org-chart?view=people');
    $this->assertMatchesOpenApi($r, 'get', '/org-chart', 200);
    expect($r->json('meta.view'))->toBe('people')->and($parent('/org-chart?view=people'))->toBe($jefa);

    dirChangeManager($ana, $otro, '2026-11-01');

    expect($parent('/org-chart?view=people'))->toBe($otro)
        ->and($parent('/org-chart?view=people&as_of=2026-10-15'))->toBe($jefa)
        ->and(dirCall($this->admin, '/directory/'.$ana)->json('data.manager.display_name'))->toBe('Óscar Paredes');
});

it('sin jefe en la asignación, el jefe es quien ocupa la posición jefe; un ciclo en los datos no rompe el árbol', function (): void {
    [$posBoss, $posAna] = TenancyScope::runAs(DIR_TENANT, function (): array {
        $boss = (string) Str::uuid();
        $mine = (string) Str::uuid();
        $base = ['tenant_id' => DIR_TENANT, 'job_profile_id' => test()->job, 'org_unit_id' => test()->fin, 'legal_entity_id' => test()->company, 'status' => 'filled'];
        DB::table('positions')->insert(['id' => $boss, 'code' => 'P-JEFE', 'title' => 'Jefe de finanzas'] + $base);
        DB::table('positions')->insert(['id' => $mine, 'code' => 'P-ANA', 'title' => 'Analista', 'reports_to_position_id' => $boss] + $base);

        return [$boss, $mine];
    });
    $jefa = dirPerson('Julia', 'Mendoza', $this->fin, ['position_id' => $posBoss]);
    $ana = dirPerson('Ana', 'Quispe', $this->fin, ['position_id' => $posAna]);
    expect(collect(dirCall($this->admin, '/org-chart?view=people')->json('data'))->firstWhere('id', $ana)['parent_id'])->toBe($jefa);

    // Ciclo: la jefa reporta a Ana en su asignación. El resultado sigue siendo un bosque.
    dirChangeManager($jefa, $ana, '2026-11-01');
    $nodes = collect(dirCall($this->admin, '/org-chart?view=people')->json('data'));
    expect($nodes->whereNull('parent_id')->count())->toBeGreaterThanOrEqual(1);
});

it('organigrama por unidades a una fecha: estructura de ese día, responsable y dotación', function (): void {
    $pos = TenancyScope::runAs(DIR_TENANT, function (): string {
        $id = (string) Str::uuid();
        DB::table('positions')->insert(['id' => $id, 'tenant_id' => DIR_TENANT, 'code' => 'P-GF', 'title' => 'Gerente de finanzas', 'job_profile_id' => test()->boss, 'org_unit_id' => test()->fin, 'legal_entity_id' => test()->company, 'status' => 'filled']);

        return $id;
    });
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->actingAs($this->admin, 'ayni')->withHeaders(['X-Tenant-Id' => DIR_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->patchJson('/api/v1/org-units/'.$this->fin, ['head_position_id' => $pos, 'effective_from' => '2025-01-01'])->assertOk();
    dirPerson('Julia', 'Mendoza', $this->fin, ['position_id' => $pos, 'job_profile_id' => $this->boss]);
    dirPerson('Ana', 'Quispe', $this->fin);

    // Tesorería pasa a depender de Operaciones desde diciembre.
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->actingAs($this->admin, 'ayni')->withHeaders(['X-Tenant-Id' => DIR_TENANT, 'Idempotency-Key' => (string) Str::uuid()])
        ->postJson('/api/v1/org-units/'.$this->tes.'/move', ['parent_id' => $this->ops, 'effective_on' => '2026-12-01'])->assertOk();

    $today = dirCall($this->admin, '/org-chart');
    $this->assertMatchesOpenApi($today, 'get', '/org-chart', 200);
    $byCode = fn ($r) => collect($r->json('data'))->keyBy('code');
    $fin = $byCode($today)['FIN'];
    expect($today->json('meta.view'))->toBe('units')
        ->and($fin['head_name'])->toBe('Julia Mendoza')
        ->and($fin['head_position_title'])->toBe('Gerente de finanzas')
        ->and($fin['people_count'])->toBe(2)
        ->and($byCode($today)['TES']['parent_id'])->toBe($this->fin)
        ->and($byCode(dirCall($this->admin, '/org-chart?as_of=2026-12-15'))['TES']['parent_id'])->toBe($this->ops);

    $this->assertMatchesOpenApi(dirCall($this->admin, '/org-chart?view=otra'), 'get', '/org-chart', 400);
});

it('sin permiso responde 403 y con el módulo apagado 403 module_not_enabled', function (): void {
    $sinRol = TenancyScope::runAs(DIR_TENANT, fn () => User::factory()->create());
    $this->assertMatchesOpenApi(dirCall($sinRol, '/directory'), 'get', '/directory', 403);
    $this->assertMatchesOpenApi(dirCall($sinRol, '/org-chart'), 'get', '/org-chart', 403);

    IdentityFixtures::disableModule(DIR_TENANT, 'core');
    $r = dirCall($this->admin, '/directory');
    $this->assertMatchesOpenApi($r, 'get', '/directory', 403);
    expect($r->json('error.code'))->toBe('module_not_enabled');
});
