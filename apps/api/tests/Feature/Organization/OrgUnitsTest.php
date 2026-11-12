<?php

/**
 * ORG-3 · Árbol de unidades organizacionales: niveles con los nombres de la organización,
 * responsable y centro de costo por unidad, reorganización con fecha efectiva que no altera la
 * estructura de periodos anteriores, sin ciclos (RN-ORG-02) y archivo con RN-ORG-01.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const OU_TENANT = 'aaaaaaaa-0e03-4000-8000-00000000000a';
const OU_OTHER = 'bbbbbbbb-0e03-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    Carbon::setTestNow('2026-11-12 10:00:00');
    TenancyScope::clear();
    foreach ([OU_TENANT => 'ou-org', OU_OTHER => 'ou-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = ouUser(OU_TENANT, 'admin_empresa');
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function ouUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

function ouCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

/** Id del nivel con ese código (los niveles iniciales se crean al primer uso). */
function ouType(User $user, string $code): string
{
    $types = ouCall($user, 'GET', '/org-unit-types')->assertOk()->json('data');

    return (string) collect($types)->firstWhere('code', $code)['id'];
}

function ouUnit(User $user, string $code, string $name, string $type, ?string $parent = null, array $extra = []): string
{
    $r = ouCall($user, 'POST', '/org-units', ['code' => $code, 'name' => $name, 'type_id' => ouType($user, $type), 'parent_id' => $parent, 'effective_from' => '2026-01-01'] + $extra);
    $r->assertCreated();

    return (string) $r->json('data.id');
}

/**
 * @return array<string, array<string, mixed>>
 */
function ouTree(User $user, ?string $asOf = null): array
{
    $r = ouCall($user, 'GET', '/org-units'.($asOf ? '?as_of='.$asOf : ''));
    $r->assertOk();

    return collect($r->json('data'))->keyBy('code')->all();
}

test('la organización parte de seis niveles, los renombra y agrega los suyos', function (): void {
    $r = ouCall($this->admin, 'GET', '/org-unit-types');
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/org-unit-types', 200);
    expect(collect($r->json('data'))->pluck('code')->all())->toBe(['management', 'area', 'department', 'subdepartment', 'unit', 'team'])
        ->and(collect($r->json('data'))->pluck('name')->all())->toContain('Gerencia', 'Equipo');

    $team = collect($r->json('data'))->firstWhere('code', 'team')['id'];
    $renamed = ouCall($this->admin, 'PATCH', '/org-unit-types/'.$team, ['name' => 'Célula']);
    $renamed->assertOk()->assertJsonPath('data.name', 'Célula');
    $this->assertMatchesOpenApi($renamed, 'patch', '/org-unit-types/{id}', 200);

    $new = ouCall($this->admin, 'POST', '/org-unit-types', ['code' => 'jefatura', 'name' => 'Jefatura', 'rank' => 4]);
    $new->assertCreated()->assertJsonPath('data.code', 'jefatura');
    $this->assertMatchesOpenApi($new, 'post', '/org-unit-types', 201);
    ouCall($this->admin, 'POST', '/org-unit-types', ['code' => 'jefatura', 'name' => 'Otra'])->assertStatus(409);

    $audit = TenancyScope::runAs(OU_TENANT, fn () => DB::table('audit_events')->where('entity_type', 'org_unit_type')->pluck('action')->all());
    expect($audit)->toContain('org_unit_type.created', 'org_unit_type.updated');

    // Un nivel propio sirve para crear unidades.
    ouCall($this->admin, 'POST', '/org-units', ['code' => 'J1', 'name' => 'Jefatura de Caja', 'type_id' => $new->json('data.id')])->assertCreated()
        ->assertJsonPath('data.type_name', 'Jefatura');
});

test('cada unidad tiene responsable y centro de costo; el árbol sale en orden con ruta y profundidad', function (): void {
    [$position, $costCenter, $entity] = TenancyScope::runAs(OU_TENANT, function (): array {
        $entity = LegalEntity::factory()->create();
        $jp = (string) Str::uuid();
        DB::table('job_profiles')->insert(['id' => $jp, 'tenant_id' => OU_TENANT, 'code' => 'GER', 'name' => 'Gerente']);
        $cc = (string) Str::uuid();
        DB::table('cost_centers')->insert(['id' => $cc, 'tenant_id' => OU_TENANT, 'legal_entity_id' => $entity->id, 'code' => 'CC-ADM', 'name' => 'Administración', 'status' => 'active']);

        return [null, $cc, $entity->id, $jp];
    });
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management', null, ['legal_entity_id' => $entity, 'cost_center_id' => $costCenter]);
    $position = TenancyScope::runAs(OU_TENANT, function () use ($g, $entity): string {
        $id = (string) Str::uuid();
        DB::table('positions')->insert(['id' => $id, 'tenant_id' => OU_TENANT, 'code' => 'P-GG', 'title' => 'Gerente General',
            'job_profile_id' => DB::table('job_profiles')->where('code', 'GER')->value('id'), 'org_unit_id' => $g, 'legal_entity_id' => $entity, 'status' => 'filled']);

        return $id;
    });

    $created = ouCall($this->admin, 'POST', '/org-units', [
        'code' => 'FIN', 'name' => 'Finanzas', 'type_id' => ouType($this->admin, 'area'), 'parent_id' => $g,
        'head_position_id' => $position, 'cost_center_id' => $costCenter, 'effective_from' => '2026-01-01',
    ]);
    $created->assertCreated()
        ->assertJsonPath('data.parent_name', 'Gerencia General')
        ->assertJsonPath('data.head_position_title', 'Gerente General')
        ->assertJsonPath('data.cost_center_name', 'Administración')
        ->assertJsonPath('data.legal_entity_id', $entity);
    $this->assertMatchesOpenApi($created, 'post', '/org-units', 201);
    $fin = (string) $created->json('data.id');
    ouUnit($this->admin, 'TES', 'Tesorería', 'department', $fin);
    ouUnit($this->admin, 'CON', 'Contabilidad', 'department', $fin);

    $r = ouCall($this->admin, 'GET', '/org-units');
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'get', '/org-units', 200);
    expect(collect($r->json('data'))->pluck('code')->all())->toBe(['GG', 'FIN', 'CON', 'TES'])
        ->and(collect($r->json('data'))->pluck('depth')->all())->toBe([0, 1, 2, 2])
        ->and($r->json('data.1.children_count'))->toBe(2)
        ->and($r->json('data.2.path'))->toBe('/'.$g.'/'.$fin.'/'.$r->json('data.2.id'));

    // La foto vigente también lleva la ruta (la usa el alcance por unidad, SEG-10).
    $path = TenancyScope::runAs(OU_TENANT, fn () => DB::table('org_units')->where('code', 'TES')->value('path'));
    expect($path)->toStartWith('/'.$g.'/'.$fin.'/');

    $show = ouCall($this->admin, 'GET', '/org-units/'.$fin);
    $show->assertOk()->assertJsonPath('data.code', 'FIN');
    $this->assertMatchesOpenApi($show, 'get', '/org-units/{id}', 200);

    $audit = TenancyScope::runAs(OU_TENANT, fn () => DB::table('audit_events')->where('entity_id', $fin)->where('action', 'org_unit.created')->first());
    expect($audit)->not->toBeNull();
    $events = TenancyScope::runAs(OU_TENANT, fn () => DB::table('outbox_events')->where('aggregate_id', $fin)->pluck('event_type')->all());
    expect($events)->toContain('org_unit.created.v1');

    // Un responsable o centro de costo inexistente es 422.
    $bad = ouCall($this->admin, 'POST', '/org-units', ['code' => 'X', 'name' => 'X', 'type_id' => ouType($this->admin, 'area'), 'cost_center_id' => (string) Str::uuid()]);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/org-units', 422);
    ouCall($this->admin, 'POST', '/org-units', ['code' => 'Y', 'name' => 'Y'])->assertStatus(422);
});

test('reorganizar con fecha efectiva no altera la estructura de periodos anteriores', function (): void {
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management');
    $ops = ouUnit($this->admin, 'OPS', 'Operaciones', 'area', $g);
    $com = ouUnit($this->admin, 'COM', 'Comercial', 'area', $g);
    $log = ouUnit($this->admin, 'LOG', 'Logística', 'department', $ops);

    // Logística pasa a Comercial desde el 1 de diciembre (fecha futura).
    $move = ouCall($this->admin, 'POST', '/org-units/'.$log.'/move', ['parent_id' => $com, 'effective_on' => '2026-12-01', 'reason' => 'Reorganización comercial']);
    $move->assertOk()->assertJsonPath('data.scheduled_change.effective_on', '2026-12-01');
    $this->assertMatchesOpenApi($move, 'post', '/org-units/{id}/move', 200);

    // Hoy y en periodos anteriores, Logística sigue en Operaciones.
    expect(ouTree($this->admin)['LOG']['parent_id'])->toBe($ops)
        ->and(ouTree($this->admin, '2026-06-30')['LOG']['parent_id'])->toBe($ops)
        ->and(ouTree($this->admin, '2026-12-01')['LOG']['parent_id'])->toBe($com)
        ->and(ouTree($this->admin, '2026-12-01')['LOG']['parent_name'])->toBe('Comercial');
    // La foto vigente (alcance de datos, organigrama) no cambia antes de la fecha.
    expect(TenancyScope::runAs(OU_TENANT, fn () => DB::table('org_units')->where('id', $log)->value('parent_id')))->toBe($ops);

    $history = ouCall($this->admin, 'GET', '/org-units/'.$log.'/history');
    $history->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/org-units/{id}/history', 200);
    expect($history->json('data'))->toHaveCount(2)
        ->and($history->json('data.0.is_scheduled'))->toBeTrue()
        ->and($history->json('data.0.reason'))->toBe('Reorganización comercial')
        ->and($history->json('data.1.valid_to'))->toBe('2026-11-30')
        ->and($history->json('data.1.parent_name'))->toBe('Operaciones');

    // El 1 de diciembre el cambio rige solo: la foto y la ruta se actualizan.
    Carbon::setTestNow('2026-12-01 00:15:00');
    Artisan::call('org:apply-structure-changes');
    $row = TenancyScope::runAs(OU_TENANT, fn () => DB::table('org_units')->where('id', $log)->first(['parent_id', 'path']));
    expect($row->parent_id)->toBe($com)->and($row->path)->toBe('/'.$g.'/'.$com.'/'.$log);
    // Y el periodo anterior sigue igual.
    expect(ouTree($this->admin, '2026-11-15')['LOG']['parent_id'])->toBe($ops);

    $events = TenancyScope::runAs(OU_TENANT, fn () => DB::table('outbox_events')->where('aggregate_id', $log)->pluck('event_type')->all());
    expect($events)->toContain('org_unit.moved.v1');
});

test('renombrar o cambiar el responsable desde una fecha guarda una versión; el estado no se cambia por PATCH', function (): void {
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management');
    $u = ouCall($this->admin, 'PATCH', '/org-units/'.$g, ['name' => 'Dirección General', 'effective_from' => '2026-11-01', 'status' => 'archived']);
    $u->assertOk()->assertJsonPath('data.name', 'Dirección General')->assertJsonPath('data.status', 'active');
    $this->assertMatchesOpenApi($u, 'patch', '/org-units/{id}', 200);

    expect(ouTree($this->admin, '2026-10-31')['GG']['name'])->toBe('Gerencia General')
        ->and(ouTree($this->admin)['GG']['name'])->toBe('Dirección General');
    $audit = TenancyScope::runAs(OU_TENANT, fn () => DB::table('audit_events')->where('entity_id', $g)->where('action', 'org_unit.updated')->count());
    expect($audit)->toBe(1);

    // Un cambio no puede regir antes de que la unidad exista.
    ouCall($this->admin, 'PATCH', '/org-units/'.$g, ['name' => 'Antes', 'effective_from' => '2025-01-01'])->assertStatus(422);
});

test('el árbol no admite ciclos (RN-ORG-02)', function (): void {
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management');
    $a = ouUnit($this->admin, 'A', 'Área A', 'area', $g);
    $d = ouUnit($this->admin, 'D', 'Depto D', 'department', $a);

    $cycle = ouCall($this->admin, 'POST', '/org-units/'.$g.'/move', ['parent_id' => $d]);
    $cycle->assertStatus(422)->assertJsonPath('error.code', 'business_rule_violated');
    $this->assertMatchesOpenApi($cycle, 'post', '/org-units/{id}/move', 422);
    ouCall($this->admin, 'POST', '/org-units/'.$a.'/move', ['parent_id' => $a])->assertStatus(422);
    ouCall($this->admin, 'PATCH', '/org-units/'.$g, ['parent_id' => $a])->assertStatus(422);
    // Mover a la raíz sí procede.
    ouCall($this->admin, 'POST', '/org-units/'.$d.'/move', ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
});

test('archivar exige no tener subunidades ni posiciones activas; las acciones respetan la máquina de estados', function (): void {
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management');
    $a = ouUnit($this->admin, 'A', 'Área A', 'area', $g);

    $blocked = ouCall($this->admin, 'POST', '/org-units/'.$g.'/archive', []);
    $blocked->assertStatus(422)->assertJsonPath('error.code', 'business_rule_violated');
    $impact = ouCall($this->admin, 'GET', '/org-units/'.$g.'/impact');
    $impact->assertOk()->assertJsonPath('data.subunits', 1);
    $this->assertMatchesOpenApi($impact, 'get', '/org-units/{id}/impact', 200);

    $archived = ouCall($this->admin, 'POST', '/org-units/'.$a.'/archive', ['reason' => 'Fusión']);
    $archived->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.valid_to', '2026-11-12');
    $this->assertMatchesOpenApi($archived, 'post', '/org-units/{id}/archive', 200);
    expect(ouTree($this->admin))->not->toHaveKey('A')
        ->and(ouTree($this->admin, '2026-11-01'))->toHaveKey('A');

    // Matriz de transiciones: archivar dos veces, mover o restaurar una activa → 409.
    $again = ouCall($this->admin, 'POST', '/org-units/'.$a.'/archive', []);
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/org-units/{id}/archive', 409);
    ouCall($this->admin, 'POST', '/org-units/'.$a.'/move', ['parent_id' => null])->assertStatus(409);
    ouCall($this->admin, 'POST', '/org-units/'.$g.'/restore', [])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    $restored = ouCall($this->admin, 'POST', '/org-units/'.$a.'/restore', []);
    $restored->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.valid_to', null);
    $this->assertMatchesOpenApi($restored, 'post', '/org-units/{id}/restore', 200);
    expect(ouTree($this->admin)['A']['parent_id'])->toBe($g);

    $actions = TenancyScope::runAs(OU_TENANT, fn () => DB::table('audit_events')->where('entity_id', $a)->pluck('action')->all());
    expect($actions)->toContain('org_unit.archived', 'org_unit.restored');
});

test('sin permiso 403, con el módulo apagado 403 module_not_enabled y una unidad de otra organización 404', function (): void {
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management');

    $employee = ouUser(OU_TENANT, 'empleado');
    $denied = ouCall($employee, 'GET', '/org-units');
    $denied->assertStatus(403)->assertJsonPath('error.meta.permission', 'org.units.view');
    $this->assertMatchesOpenApi($denied, 'get', '/org-units', 403);

    // Quien solo ve unidades (Manager) no crea ni mueve.
    $manager = ouUser(OU_TENANT, 'manager');
    ouCall($manager, 'GET', '/org-units')->assertOk();
    ouCall($manager, 'POST', '/org-units', ['code' => 'X', 'name' => 'X', 'type_id' => ouType($this->admin, 'area')])->assertStatus(403);
    ouCall($manager, 'POST', '/org-units/'.$g.'/move', ['parent_id' => null])->assertStatus(403);

    $intruder = ouUser(OU_OTHER, 'admin_empresa');
    ouCall($intruder, 'GET', '/org-units/'.$g)->assertNotFound();
    ouCall($intruder, 'PATCH', '/org-units/'.$g, ['name' => 'Tomada'])->assertNotFound();
    ouCall($intruder, 'POST', '/org-units/'.$g.'/archive', [])->assertNotFound();
    ouCall($intruder, 'GET', '/org-units/'.$g.'/history')->assertNotFound();
    expect(ouTree($intruder))->toBe([]);

    IdentityFixtures::disableModule(OU_TENANT, 'core');
    ouCall($this->admin, 'GET', '/org-units')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

test('el alcance por unidad (SEG-10) sigue a la unidad cuando se mueve con fecha de hoy', function (): void {
    $g = ouUnit($this->admin, 'GG', 'Gerencia General', 'management');
    $a = ouUnit($this->admin, 'A', 'Área A', 'area', $g);
    $b = ouUnit($this->admin, 'B', 'Área B', 'area', $g);
    $d = ouUnit($this->admin, 'D', 'Depto D', 'department', $a);

    ouCall($this->admin, 'POST', '/org-units/'.$d.'/move', ['parent_id' => $b])->assertOk();
    $parent = TenancyScope::runAs(OU_TENANT, fn () => DB::table('org_units')->where('id', $d)->value('parent_id'));
    expect($parent)->toBe($b);
    // La descendencia que usa el alcance por unidad ya refleja el cambio.
    $underB = TenancyScope::runAs(OU_TENANT, fn () => collect(DB::select('WITH RECURSIVE t AS (SELECT id FROM org_units WHERE id = ? UNION SELECT c.id FROM org_units c JOIN t ON c.parent_id = t.id) SELECT id FROM t', [$b]))->pluck('id')->all());
    expect($underB)->toContain($d);
});
