<?php

/**
 * SEG-6 · Usuarios e invitaciones (ME-02 · Cuenta de usuario).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const USR_TENANT = 'aaaaaaaa-6060-4000-8000-00000000000a';
const USR_OTHER = 'bbbbbbbb-6060-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => USR_TENANT, 'slug' => 'usr-org-a', 'name' => 'Usr A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => USR_OTHER, 'slug' => 'usr-org-b', 'name' => 'Usr B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [USR_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [USR_OTHER]);

    $this->admin = TenancyScope::runAs(USR_TENANT, function (): User {
        $user = User::factory()->create(['display_name' => 'Admin']);
        $user->roleAssignments()->create(['role_id' => usrRole('admin_empresa')]);

        return $user;
    });
});

afterEach(function (): void {
    TenancyScope::clear();
});

function usrRole(string $systemCode, string $tenant = USR_TENANT): string
{
    return TenancyScope::runAs($tenant, fn () => (string) Role::query()->where('system_role_code', $systemCode)->value('id'));
}

function usrCall(User $user, string $method, string $path, array $body = [], array $headers = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id] + $headers)
        ->json($method, '/api/v1'.$path, $body);
}

function usrInvitationToken(string $userId): string
{
    return TenancyScope::runAs(USR_TENANT, function () use ($userId): string {
        $event = OutboxEvent::query()->where('event_type', DomainEventTypes::USER_INVITED)->where('aggregate_id', $userId)->latest('occurred_at')->firstOrFail();

        return Crypt::decryptString($event->payload['token_enc']);
    });
}

test('RRHH invita a un usuario: queda invitado, con sus roles y la invitación sale por el outbox', function (): void {
    $response = usrCall($this->admin, 'POST', '/users', [
        'login' => 'rquispe', 'display_name' => 'Rosa Quispe', 'email' => 'rosa@empresa.pe', 'role_ids' => [usrRole('empleado')],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'invited')
        ->assertJsonPath('data.login', 'rquispe')
        ->assertJsonPath('data.email', 'rosa@empresa.pe')
        ->assertJsonPath('data.role_ids', [usrRole('empleado')]);
    $this->assertMatchesOpenApi($response, 'post', '/users', 201);

    $id = $response->json('data.id');
    TenancyScope::runAs(USR_TENANT, function () use ($id): void {
        expect(OutboxEvent::query()->where('event_type', DomainEventTypes::USER_INVITED)->where('aggregate_id', $id)->count())->toBe(1)
            ->and(DB::table('audit_events')->where('entity_id', $id)->where('action', 'user.created')->count())->toBe(1);
    });
});

test('el correo no es obligatorio (RN-SEG-09) y la clave no se fija al crear', function (): void {
    $response = usrCall($this->admin, 'POST', '/users', ['login' => 'operario01']);

    $response->assertCreated()->assertJsonPath('data.email', null)->assertJsonPath('data.status', 'invited');
    TenancyScope::runAs(USR_TENANT, fn () => expect(User::query()->find($response->json('data.id'))->password_hash)->toBeNull());
});

test('login o correo repetido responde 409 duplicate', function (): void {
    usrCall($this->admin, 'POST', '/users', ['login' => 'jperez', 'email' => 'j@empresa.pe'])->assertCreated();

    usrCall($this->admin, 'POST', '/users', ['login' => 'JPEREZ'])->assertStatus(409)->assertJsonPath('error.meta.fields', ['login']);
    $dup = usrCall($this->admin, 'POST', '/users', ['login' => 'otro', 'email' => 'J@Empresa.pe']);
    $dup->assertStatus(409)->assertJsonPath('error.code', 'duplicate');
    $this->assertMatchesOpenApi($dup, 'post', '/users', 409);
});

test('el estado no se cambia por PATCH (regla 3)', function (): void {
    $id = usrCall($this->admin, 'POST', '/users', ['login' => 'estado1'])->json('data.id');

    $response = usrCall($this->admin, 'PATCH', "/users/{$id}", ['status' => 'active']);
    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($response, 'patch', '/users/{id}', 422);
});

test('el invitado activa su cuenta con el enlace, define su clave y queda con sesión', function (): void {
    $id = usrCall($this->admin, 'POST', '/users', ['login' => 'activa', 'email' => 'activa@empresa.pe'])->json('data.id');
    $raw = usrInvitationToken($id);

    TenancyScope::clear();
    app('auth')->forgetGuards();
    $response = $this->withHeaders(['X-Tenant-Id' => USR_TENANT])->postJson('/api/v1/auth/invitation/accept', [
        'token' => $raw, 'password' => 'Mi-Clave-Nueva-2026', 'password_confirmation' => 'Mi-Clave-Nueva-2026',
    ]);
    $response->assertOk();
    $this->assertMatchesOpenApi($response, 'post', '/auth/invitation/accept', 200);
    expect($response->getCookie('gestia_session', false))->not->toBeNull();

    usrCall($this->admin, 'GET', "/users/{$id}")->assertJsonPath('data.status', 'active');

    // De un solo uso.
    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => USR_TENANT])->postJson('/api/v1/auth/invitation/accept', [
        'token' => $raw, 'password' => 'Otra-Clave-2026x', 'password_confirmation' => 'Otra-Clave-2026x',
    ])->assertStatus(422)->assertJsonPath('error.code', 'invalid_token');

    // Ahora puede ingresar con su login.
    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => USR_TENANT])->postJson('/api/v1/auth/login', ['login' => 'activa', 'password' => 'Mi-Clave-Nueva-2026'])->assertOk();
});

test('la invitación caduca y puede reenviarse; el reenvío invalida la anterior', function (): void {
    $id = usrCall($this->admin, 'POST', '/users', ['login' => 'caduca', 'email' => 'caduca@empresa.pe'])->json('data.id');
    $first = usrInvitationToken($id);

    $this->travel((int) config('identity.invitation_ttl_hours') + 1)->hours();
    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => USR_TENANT])->postJson('/api/v1/auth/invitation/accept', [
        'token' => $first, 'password' => 'Mi-Clave-Nueva-2026', 'password_confirmation' => 'Mi-Clave-Nueva-2026',
    ])->assertStatus(422);

    $resend = usrCall($this->admin, 'POST', "/users/{$id}/invite", []);
    $resend->assertOk()->assertJsonPath('data.status', 'invited');
    $this->assertMatchesOpenApi($resend, 'post', '/users/{id}/invite', 200);
    $second = usrInvitationToken($id);
    expect($second)->not->toBe($first);

    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => USR_TENANT])->postJson('/api/v1/auth/invitation/accept', [
        'token' => $second, 'password' => 'Mi-Clave-Nueva-2026', 'password_confirmation' => 'Mi-Clave-Nueva-2026',
    ])->assertOk();
});

test('el suscriptor envía la invitación por el puerto de correo', function (): void {
    $id = usrCall($this->admin, 'POST', '/users', ['login' => 'correo', 'email' => 'correo@empresa.pe'])->json('data.id');
    $raw = usrInvitationToken($id);
    $fake = new FakeEmailAdapter;
    app(IntegrationManager::class)->setEmailAdapter($fake);

    $event = TenancyScope::runAs(USR_TENANT, fn () => OutboxEvent::query()->where('event_type', DomainEventTypes::USER_INVITED)->where('aggregate_id', $id)->sole());
    foreach (app(OutboxService::class)->getSubscribersFor($event->event_type) as $subscriber) {
        $subscriber->handle($event);
    }

    expect($fake->getSent())->toHaveCount(1)
        ->and($fake->getSent()[0]['to'])->toBe('correo@empresa.pe')
        ->and($fake->getSent()[0]['body'])->toContain('/activar-cuenta?token='.$raw);
});

test('reenviar invitación a un usuario activo responde 409 invalid_transition', function (): void {
    $active = TenancyScope::runAs(USR_TENANT, fn () => User::factory()->create());

    $response = usrCall($this->admin, 'POST', "/users/{$active->id}/invite", []);
    $response->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition')->assertJsonPath('error.meta.current_status', 'active');
    $this->assertMatchesOpenApi($response, 'post', '/users/{id}/invite', 409);
});

test('suspender cierra sesiones y no altera la persona; reactivar devuelve el acceso', function (): void {
    $target = TenancyScope::runAs(USR_TENANT, function (): User {
        $u = User::factory()->create();
        UserSession::create(['user_id' => $u->id, 'session_hash' => hash('sha256', 'x'), 'expires_at' => now()->addHour()]);

        return $u;
    });

    usrCall($this->admin, 'POST', "/users/{$target->id}/suspend", [])->assertStatus(422);

    $suspend = usrCall($this->admin, 'POST', "/users/{$target->id}/suspend", ['reason' => 'Investigación interna']);
    $suspend->assertOk()->assertJsonPath('data.status', 'locked');
    $this->assertMatchesOpenApi($suspend, 'post', '/users/{id}/suspend', 200);
    TenancyScope::runAs(USR_TENANT, function () use ($target): void {
        expect(UserSession::query()->where('user_id', $target->id)->whereNull('revoked_at')->count())->toBe(0);
        $audit = DB::table('audit_events')->where('entity_id', $target->id)->where('action', 'user.suspended')->sole();
        expect($audit->reason)->toBe('Investigación interna');
    });

    $reactivate = usrCall($this->admin, 'POST', "/users/{$target->id}/reactivate", []);
    $reactivate->assertOk()->assertJsonPath('data.status', 'active');
    $this->assertMatchesOpenApi($reactivate, 'post', '/users/{id}/reactivate', 200);
});

test('RN-SEG-02: no se puede suspender ni quitar el rol al último administrador activo', function (): void {
    usrCall($this->admin, 'POST', "/users/{$this->admin->id}/suspend", ['reason' => 'prueba'])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SEG-02');
    usrCall($this->admin, 'PATCH', "/users/{$this->admin->id}", ['role_ids' => [usrRole('rrhh')]])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SEG-02');

    // Con otro administrador activo sí se puede.
    TenancyScope::runAs(USR_TENANT, fn () => User::factory()->create()->roleAssignments()->create(['role_id' => usrRole('admin_empresa')]));
    usrCall($this->admin, 'PATCH', "/users/{$this->admin->id}", ['role_ids' => [usrRole('admin_empresa'), usrRole('rrhh')]])->assertOk();
});

test('cambiar roles queda auditado y se aplica de inmediato', function (): void {
    $target = TenancyScope::runAs(USR_TENANT, fn () => User::factory()->create());
    usrCall($target, 'GET', '/users')->assertStatus(403);

    $patch = usrCall($this->admin, 'PATCH', "/users/{$target->id}", ['role_ids' => [usrRole('auditor')]]);
    $patch->assertOk()->assertJsonPath('data.role_ids', [usrRole('auditor')]);
    $this->assertMatchesOpenApi($patch, 'patch', '/users/{id}', 200);

    usrCall($target, 'GET', '/users')->assertOk();
});

test('restablecer clave obliga a cambiarla, cierra sesiones y envía enlace; restablecer MFA exige motivo', function (): void {
    $target = TenancyScope::runAs(USR_TENANT, fn () => User::factory()->withEmail('mfa@empresa.pe')->create());

    $reset = usrCall($this->admin, 'POST', "/users/{$target->id}/reset-password", []);
    $reset->assertOk();
    $this->assertMatchesOpenApi($reset, 'post', '/users/{id}/reset-password', 200);
    TenancyScope::runAs(USR_TENANT, function () use ($target): void {
        expect(User::query()->find($target->id)->must_change_password)->toBeTrue()
            ->and(OutboxEvent::query()->where('event_type', DomainEventTypes::USER_PASSWORD_RESET_REQUESTED)->where('aggregate_id', $target->id)->count())->toBe(1);
    });

    usrCall($this->admin, 'POST', "/users/{$target->id}/reset-mfa", [])->assertStatus(422);
    $mfa = usrCall($this->admin, 'POST', "/users/{$target->id}/reset-mfa", ['reason' => 'Perdió el celular']);
    $mfa->assertOk()->assertJsonPath('data.mfa_enabled', false);
    $this->assertMatchesOpenApi($mfa, 'post', '/users/{id}/reset-mfa', 200);
});

test('listado con búsqueda y filtro de estado', function (): void {
    usrCall($this->admin, 'POST', '/users', ['login' => 'busca1', 'display_name' => 'Zoila Mamani'])->assertCreated();

    $response = usrCall($this->admin, 'GET', '/users?q=mamani&status=invited');
    $response->assertOk();
    expect(collect($response->json('data'))->pluck('display_name')->all())->toBe(['Zoila Mamani']);
    $this->assertMatchesOpenApi($response, 'get', '/users', 200);
});

test('sin permiso responde 403; un usuario de otra organización responde 404', function (): void {
    $viewer = IdentityFixtures::userWithPermissions(USR_TENANT, ['identity.users.view']);
    $forbidden = usrCall($viewer, 'POST', '/users', ['login' => 'nope']);
    $forbidden->assertStatus(403)->assertJsonPath('error.meta.permission', 'identity.users.create');
    $this->assertMatchesOpenApi($forbidden, 'post', '/users', 403);

    $foreign = TenancyScope::runAs(USR_OTHER, fn () => User::factory()->create());
    usrCall($this->admin, 'GET', "/users/{$foreign->id}")->assertNotFound();
    usrCall($this->admin, 'POST', "/users/{$foreign->id}/suspend", ['reason' => 'x y z'])->assertNotFound();
});

test('RN-SEG-03: quien no es administrador no asigna roles con permisos que no tiene', function (): void {
    $hr = IdentityFixtures::userWithPermissions(USR_TENANT, ['identity.users.view', 'identity.users.create', 'identity.users.invite']);

    usrCall($hr, 'POST', '/users', ['login' => 'escala', 'role_ids' => [usrRole('admin_empresa')]])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SEG-03');
});
