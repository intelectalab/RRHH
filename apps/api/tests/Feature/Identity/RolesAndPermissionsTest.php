<?php

/**
 * SEG-7 · Roles del sistema y personalizados · SEG-8 · Catálogo y autorización · GET /me.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const ROL_TENANT = 'aaaaaaaa-7070-4000-8000-00000000000a';
const ROL_OTHER = 'bbbbbbbb-7070-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => ROL_TENANT, 'slug' => 'rol-org-a', 'name' => 'Rol A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => ROL_OTHER, 'slug' => 'rol-org-b', 'name' => 'Rol B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [ROL_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [ROL_OTHER]);
});

afterEach(function (): void {
    TenancyScope::clear();
});

function rolAdmin(): User
{
    return TenancyScope::runAs(ROL_TENANT, function (): User {
        $user = User::factory()->create(['display_name' => 'Admin A']);
        $admin = Role::query()->where('system_role_code', 'admin_empresa')->firstOrFail();
        $user->roleAssignments()->create(['role_id' => $admin->id]);

        return $user;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function rolCall(User $user, string $method, string $path, array $body = [], array $headers = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id] + $headers)
        ->json($method, '/api/v1'.$path, $body);
}

test('seed_tenant_defaults crea los roles de sistema con sus permisos', function (): void {
    TenancyScope::runAs(ROL_TENANT, function (): void {
        expect(Role::query()->where('is_system', true)->pluck('system_role_code')->sort()->values()->all())
            ->toContain('admin_empresa', 'rrhh', 'payroll', 'manager', 'empleado', 'auditor');
        $admin = Role::query()->where('system_role_code', 'admin_empresa')->sole();
        expect($admin->grants()->where('permission_code', 'identity.roles.create')->exists())->toBeTrue();
    });
});

test('GET /me entrega usuario, organización, roles, permisos efectivos y módulos', function (): void {
    $response = rolCall(rolAdmin(), 'GET', '/me');

    $response->assertOk()
        ->assertJsonPath('data.user.display_name', 'Admin A')
        ->assertJsonPath('data.tenant.slug', 'rol-org-a')
        ->assertJsonPath('data.roles.0.code', 'admin_empresa');
    expect($response->json('data.permissions'))->toContain('identity.roles.view', 'identity.users.invite')
        ->and($response->json('data.modules'))->toContain('base')
        ->and($response->json('data.scopes')['identity.roles.view'])->toBe('organization');
    $this->assertMatchesOpenApi($response, 'get', '/me', 200);
});

test('GET /me sin sesión responde 401', function (): void {
    $response = $this->withHeaders(['X-Tenant-Id' => ROL_TENANT])->getJson('/api/v1/me');

    $response->assertStatus(401);
    $this->assertMatchesOpenApi($response, 'get', '/me', 401);
});

test('el catálogo de permisos se publica con acción y campo', function (): void {
    $response = rolCall(rolAdmin(), 'GET', '/permissions');

    $response->assertOk();
    $kinds = collect($response->json('data'))->pluck('kind')->unique()->sort()->values()->all();
    expect($kinds)->toBe(['action', 'field']);
    $this->assertMatchesOpenApi($response, 'get', '/permissions', 200);
});

test('el administrador lista roles incluidos los de sistema', function (): void {
    $response = rolCall(rolAdmin(), 'GET', '/roles?per_page=50');

    $response->assertOk();
    expect(collect($response->json('data'))->firstWhere('code', 'admin_empresa')['system'])->toBeTrue();
    $this->assertMatchesOpenApi($response, 'get', '/roles', 200);
});

test('un rol personalizado se crea marcando permisos y queda auditado', function (): void {
    $admin = rolAdmin();
    $response = rolCall($admin, 'POST', '/roles', [
        'name' => 'Administrador sin sueldos',
        'permissions' => ['identity.users.view', 'identity.users.invite'],
        'scope' => 'site',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.system', false)
        ->assertJsonPath('data.scope', 'site')
        ->assertJsonPath('data.permissions', ['identity.users.invite', 'identity.users.view']);
    $this->assertMatchesOpenApi($response, 'post', '/roles', 201);

    TenancyScope::runAs(ROL_TENANT, function () use ($response, $admin): void {
        $audit = DB::table('audit_events')->where('entity_id', $response->json('data.id'))->sole();
        expect($audit->action)->toBe('role.created')
            ->and($audit->actor_user_id)->toBe($admin->id)
            ->and($audit->before_data)->toBeNull()
            ->and(json_decode((string) $audit->after_data, true)['name'])->toBe('Administrador sin sueldos');
    });
});

test('un permiso inexistente o del tipo equivocado responde 422', function (): void {
    $response = rolCall(rolAdmin(), 'POST', '/roles', ['name' => 'Malo', 'permissions' => ['no.existe.nunca']]);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($response, 'post', '/roles', 422);
});

test('el cambio de permisos de un rol se aplica de inmediato y queda auditado con antes y después', function (): void {
    $admin = rolAdmin();
    $roleId = rolCall($admin, 'POST', '/roles', ['name' => 'Lector', 'permissions' => ['identity.roles.view']])->json('data.id');
    $reader = TenancyScope::runAs(ROL_TENANT, function () use ($roleId): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => $roleId]);

        return $u;
    });

    rolCall($reader, 'GET', '/roles')->assertOk();

    $response = rolCall($admin, 'PATCH', "/roles/{$roleId}", ['permissions' => ['identity.users.view']]);
    $response->assertOk();
    $this->assertMatchesOpenApi($response, 'patch', '/roles/{id}', 200);

    rolCall($reader, 'GET', '/roles')->assertStatus(403)->assertJsonPath('error.meta.permission', 'identity.roles.view');

    TenancyScope::runAs(ROL_TENANT, function () use ($roleId): void {
        $audit = DB::table('audit_events')->where('entity_id', $roleId)->where('action', 'role.updated')->sole();
        expect(json_decode((string) $audit->before_data, true)['permissions'])->toBe(['identity.roles.view'])
            ->and(json_decode((string) $audit->after_data, true)['permissions'])->toBe(['identity.users.view'])
            ->and($audit->changed_fields)->toContain('permissions');
    });
});

test('If-Match desactualizado responde 409 version_conflict', function (): void {
    $admin = rolAdmin();
    $created = rolCall($admin, 'POST', '/roles', ['name' => 'Versionado']);
    $id = $created->json('data.id');
    rolCall($admin, 'PATCH', "/roles/{$id}", ['name' => 'Versionado 2'])->assertOk();

    rolCall($admin, 'PATCH', "/roles/{$id}", ['name' => 'Versionado 3'], ['If-Match' => '"'.$created->json('data.version').'"'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'version_conflict');
});

test('los roles de sistema no se editan ni se eliminan, pero se duplican', function (): void {
    $admin = rolAdmin();
    $systemId = TenancyScope::runAs(ROL_TENANT, fn () => Role::query()->where('system_role_code', 'rrhh')->value('id'));

    rolCall($admin, 'PATCH', "/roles/{$systemId}", ['name' => 'Otro nombre'])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SEG-01');
    $delete = rolCall($admin, 'DELETE', "/roles/{$systemId}");
    $delete->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SEG-01');

    $copy = rolCall($admin, 'POST', "/roles/{$systemId}/duplicate", []);
    $copy->assertOk()->assertJsonPath('data.system', false)->assertJsonPath('data.name', 'Copia de RRHH');
    $this->assertMatchesOpenApi($copy, 'post', '/roles/{id}/duplicate', 200);

    $source = rolCall($admin, 'GET', "/roles/{$systemId}")->json('data');
    expect($copy->json('data.permissions'))->toBe($source['permissions'])
        ->and($copy->json('data.field_permissions'))->toBe($source['field_permissions']);
});

test('un rol personalizado se elimina (204) salvo que esté asignado (409 in_use)', function (): void {
    $admin = rolAdmin();
    $free = rolCall($admin, 'POST', '/roles', ['name' => 'Libre'])->json('data.id');
    $used = rolCall($admin, 'POST', '/roles', ['name' => 'Usado'])->json('data.id');
    TenancyScope::runAs(ROL_TENANT, fn () => User::factory()->create()->roleAssignments()->create(['role_id' => $used]));

    $ok = rolCall($admin, 'DELETE', "/roles/{$free}");
    $ok->assertNoContent();
    $this->assertMatchesOpenApi($ok, 'delete', '/roles/{id}', 204);
    rolCall($admin, 'GET', "/roles/{$free}")->assertNotFound();

    rolCall($admin, 'DELETE', "/roles/{$used}")->assertStatus(409)->assertJsonPath('error.code', 'in_use');
});

test('RN-SEG-03: quien no es administrador no concede permisos que no tiene', function (): void {
    $editor = IdentityFixtures::userWithPermissions(ROL_TENANT, ['identity.roles.view', 'identity.roles.create']);

    rolCall($editor, 'POST', '/roles', ['name' => 'Escalada', 'permissions' => ['identity.users.suspend']])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SEG-03');
    rolCall($editor, 'POST', '/roles', ['name' => 'Permitida', 'permissions' => ['identity.roles.view']])
        ->assertCreated();
});

test('sin el permiso de la acción responde 403 aunque el botón esté oculto', function (): void {
    $viewer = IdentityFixtures::userWithPermissions(ROL_TENANT, ['identity.roles.view']);

    $response = rolCall($viewer, 'POST', '/roles', ['name' => 'No debería']);
    $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden')->assertJsonPath('error.meta.permission', 'identity.roles.create');
    $this->assertMatchesOpenApi($response, 'post', '/roles', 403);
});

test('con el módulo apagado responde 403 module_not_enabled', function (): void {
    $admin = rolAdmin();
    IdentityFixtures::disableModule(ROL_TENANT, 'base');

    rolCall($admin, 'GET', '/roles')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled')->assertJsonPath('error.meta.module', 'base');
});

test('un rol de otra organización responde 404', function (): void {
    $otherRole = TenancyScope::runAs(ROL_OTHER, fn () => Role::query()->where('system_role_code', 'rrhh')->value('id'));

    rolCall(rolAdmin(), 'GET', "/roles/{$otherRole}")->assertNotFound();
    rolCall(rolAdmin(), 'POST', "/roles/{$otherRole}/duplicate", [])->assertNotFound();
});

test('un rol de sistema creado por la API es rechazado', function (): void {
    rolCall(rolAdmin(), 'POST', '/roles', ['name' => 'Falso sistema', 'system' => true])
        ->assertStatus(422);
});
