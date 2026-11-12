<?php

/**
 * UI-4 · Tablas profesionales: vistas guardadas por usuario y listados con orden y paginación en servidor.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Settings\Infrastructure\Models\SavedView;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const TBL_TENANT = 'aaaaaaaa-7474-4000-8000-00000000000a';
const TBL_OTHER = 'bbbbbbbb-7474-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([[TBL_TENANT, 'tbl-org'], [TBL_OTHER, 'tbl-otra']] as [$id, $slug]) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Org '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN', 'default_locale' => 'es']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
    }
    $this->admin = tblUser(TBL_TENANT, 'admin_empresa', 'Ana Admin');
    $this->rrhh = tblUser(TBL_TENANT, 'rrhh', 'Rita RRHH');
});

afterEach(fn () => TenancyScope::clear());

function tblUser(string $tenant, ?string $role, string $name, array $extra = []): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $name, $extra): User {
        $u = User::factory()->create(['display_name' => $name] + $extra);
        if ($role !== null) {
            $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);
        }

        return $u;
    });
}

function tblCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

test('una vista guardada conserva filtros, columnas y orden y vuelve igual al abrirla', function (): void {
    $created = tblCall($this->admin, 'POST', '/saved-views', [
        'resource' => 'users', 'name' => 'Bloqueados', 'filters' => ['status' => 'locked', 'q' => 'ana'],
        'columns' => ['user', 'status', 'last_login_at'], 'sort' => '-last_login_at',
    ]);
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/saved-views', 201);
    expect($created->json('data.mine'))->toBeTrue()->and($created->json('data.shared'))->toBeFalse();

    $id = $created->json('data.id');
    $shown = tblCall($this->admin, 'GET', "/saved-views/{$id}");
    $shown->assertOk();
    $this->assertMatchesOpenApi($shown, 'get', '/saved-views/{id}', 200);
    // jsonb no conserva el orden de las claves: se compara el contenido.
    expect($shown->json('data.filters'))->toEqual(['status' => 'locked', 'q' => 'ana'])
        ->and($shown->json('data.columns'))->toBe(['user', 'status', 'last_login_at'])
        ->and($shown->json('data.sort'))->toBe('-last_login_at');

    $updated = tblCall($this->admin, 'PATCH', "/saved-views/{$id}", ['columns' => ['user', 'status'], 'shared' => true]);
    $updated->assertOk();
    $this->assertMatchesOpenApi($updated, 'patch', '/saved-views/{id}', 200);
    // Lo que no se envía se conserva.
    expect($updated->json('data.filters'))->toEqual(['status' => 'locked', 'q' => 'ana'])
        ->and($updated->json('data.columns'))->toBe(['user', 'status'])->and($updated->json('data.shared'))->toBeTrue();

    $list = tblCall($this->admin, 'GET', '/saved-views?filter[resource]=users');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/saved-views', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$id]);

    $deleted = tblCall($this->admin, 'DELETE', "/saved-views/{$id}");
    $deleted->assertNoContent();
    $this->assertMatchesOpenApi($deleted, 'delete', '/saved-views/{id}', 204);
    expect(TenancyScope::runAs(TBL_TENANT, fn () => SavedView::query()->whereKey($id)->exists()))->toBeFalse();
});

test('las vistas privadas de otro no se ven; las compartidas se ven pero solo su dueño las cambia', function (): void {
    $private = tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'users', 'name' => 'Mía', 'columns' => ['user']])->json('data.id');
    $shared = tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'users', 'name' => 'Del equipo', 'columns' => ['user'], 'shared' => true])->json('data.id');

    $list = tblCall($this->rrhh, 'GET', '/saved-views?filter[resource]=users');
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$shared])
        ->and($list->json('data.0.mine'))->toBeFalse();

    $hidden = tblCall($this->rrhh, 'GET', "/saved-views/{$private}");
    $hidden->assertNotFound();
    $this->assertMatchesOpenApi($hidden, 'get', '/saved-views/{id}', 404);

    $patch = tblCall($this->rrhh, 'PATCH', "/saved-views/{$shared}", ['name' => 'Cambiada']);
    $patch->assertForbidden();
    $this->assertMatchesOpenApi($patch, 'patch', '/saved-views/{id}', 403);
    tblCall($this->rrhh, 'DELETE', "/saved-views/{$shared}")->assertForbidden();
});

test('validaciones: datos inválidos, nombre repetido y permiso', function (): void {
    $bad = tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'Users!', 'name' => '', 'columns' => 'x']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/saved-views', 422);
    expect(collect($bad->json('error.details'))->pluck('field')->all())->toContain('resource', 'name', 'columns');

    tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'users', 'name' => 'Activos'])->assertCreated();
    $dup = tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'users', 'name' => 'activos']);
    $dup->assertStatus(409);
    $this->assertMatchesOpenApi($dup, 'post', '/saved-views', 409);

    // El mismo nombre en otra tabla sí se admite.
    tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'legal_entities', 'name' => 'Activos'])->assertCreated();

    $nobody = tblUser(TBL_TENANT, null, 'Sin rol');
    $denied = tblCall($nobody, 'GET', '/saved-views');
    $denied->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'get', '/saved-views', 403);
    tblCall($nobody, 'POST', '/saved-views', ['resource' => 'users', 'name' => 'X'])->assertForbidden();

    $filter = tblCall($this->admin, 'GET', '/saved-views?filter[owner]=x');
    $filter->assertStatus(400);
    $this->assertMatchesOpenApi($filter, 'get', '/saved-views', 400);
});

test('las vistas de otra organización no se ven ni se tocan', function (): void {
    $id = tblCall($this->admin, 'POST', '/saved-views', ['resource' => 'users', 'name' => 'Compartida', 'shared' => true])->json('data.id');
    $outsider = tblUser(TBL_OTHER, 'admin_empresa', 'Otro Admin');

    expect(tblCall($outsider, 'GET', '/saved-views')->json('data'))->toBe([]);
    tblCall($outsider, 'GET', "/saved-views/{$id}")->assertNotFound();
    tblCall($outsider, 'PATCH', "/saved-views/{$id}", ['name' => 'X'])->assertNotFound();
    tblCall($outsider, 'DELETE', "/saved-views/{$id}")->assertNotFound();
});

test('la tabla de usuarios ordena y pagina en servidor con total', function (): void {
    foreach (['Zoe Zapata', 'Bruno Bravo', 'Carla Campos'] as $name) {
        tblUser(TBL_TENANT, 'empleado', $name);
    }
    tblUser(TBL_TENANT, 'empleado', 'Luis Bloqueado', ['status' => 'locked']);

    $first = tblCall($this->admin, 'GET', '/users?page=1&per_page=2&sort=display_name');
    $first->assertOk();
    $this->assertMatchesOpenApi($first, 'get', '/users', 200);
    expect($first->json('meta.total'))->toBe(6)->and($first->json('meta.page'))->toBe(1)
        ->and(collect($first->json('data'))->pluck('display_name')->all())->toBe(['Ana Admin', 'Bruno Bravo']);

    $third = tblCall($this->admin, 'GET', '/users?page=3&per_page=2&sort=display_name');
    expect(collect($third->json('data'))->pluck('display_name')->all())->toBe(['Rita RRHH', 'Zoe Zapata']);

    $desc = tblCall($this->admin, 'GET', '/users?per_page=1&sort=-display_name');
    expect($desc->json('data.0.display_name'))->toBe('Zoe Zapata');

    $locked = tblCall($this->admin, 'GET', '/users?filter[status]=locked');
    expect($locked->json('meta.total'))->toBe(1)->and($locked->json('data.0.display_name'))->toBe('Luis Bloqueado');

    $byRole = tblCall($this->admin, 'GET', '/users?filter[role_id]='.TenancyScope::runAs(TBL_TENANT, fn () => Role::query()->where('system_role_code', 'rrhh')->value('id')));
    expect(collect($byRole->json('data'))->pluck('display_name')->all())->toBe(['Rita RRHH']);

    $badSort = tblCall($this->admin, 'GET', '/users?sort=password_hash');
    $badSort->assertStatus(400)->assertJsonPath('error.code', 'invalid_filter');
    $this->assertMatchesOpenApi($badSort, 'get', '/users', 400);
    tblCall($this->admin, 'GET', '/users?filter[email]=x')->assertStatus(400);
});

test('la tabla de empresas ordena, filtra por país y conserva la paginación por cursor', function (): void {
    DB::table('countries')->insertOrIgnore(['code' => 'CL', 'name' => 'Chile', 'default_currency' => 'CLP']);
    TenancyScope::runAs(TBL_TENANT, function (): void {
        foreach ([['Beta SAC', '20100000002', 'PE'], ['Alfa SAC', '20100000001', 'PE'], ['Gamma SpA', '76100000-1', 'CL']] as [$name, $tax, $country]) {
            DB::table('legal_entities')->insert(['id' => (string) Str::uuid(), 'tenant_id' => TBL_TENANT, 'legal_name' => $name, 'tax_id' => $tax, 'country_code' => $country, 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active']);
        }
    });

    $page = tblCall($this->admin, 'GET', '/legal-entities?sort=name&per_page=2');
    $page->assertOk();
    $this->assertMatchesOpenApi($page, 'get', '/legal-entities', 200);
    expect($page->json('meta.total'))->toBe(3)->and(collect($page->json('data'))->pluck('name')->all())->toBe(['Alfa SAC', 'Beta SAC']);

    $chile = tblCall($this->admin, 'GET', '/legal-entities?filter[country]=cl');
    expect(collect($chile->json('data'))->pluck('name')->all())->toBe(['Gamma SpA']);

    $byTax = tblCall($this->admin, 'GET', '/legal-entities?sort=-tax_id&per_page=1');
    expect($byTax->json('data.0.name'))->toBe('Gamma SpA');

    // Con cursor sigue respondiendo como antes (PLT-11).
    $cursor = tblCall($this->admin, 'GET', '/legal-entities?per_page=2&cursor=');
    $cursor->assertOk();
    expect($cursor->json('data'))->toHaveCount(2);
});
