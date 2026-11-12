<?php

/**
 * SAS-2 · Organizaciones desde la consola: alta con primera empresa y primer administrador, ficha con
 * metadatos y consumo, cambios de estado por acciones (ME-40) con historial, aviso y auditoría.
 * SAS-5 · Módulos por organización: activar sin migrar datos, retirar con solo lectura (RN-SAS-06).
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Subscribers\NotifyOrganizationStatus;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const TA_TENANT = 'aaaaaaaa-5a52-4000-8000-00000000000a';
const TA_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => TA_TENANT, 'slug' => 'ta-andina', 'name' => 'Grupo Andino', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [TA_TENANT]);
    // Organización con solo Core HR y Vacaciones (además de la base).
    TenancyScope::runAs(TA_TENANT, fn () => DB::table('tenant_modules')->insertOrIgnore([
        'tenant_id' => TA_TENANT, 'module_code' => 'time_off', 'status' => 'active', 'source' => 'plan', 'activated_at' => now(),
    ]));
    $this->admin = taTenantUser('admin_empresa');

    $this->owner = taConsoleUser('duena-ta@gestia.test', 'superadmin');
    $this->token = taEnrolled('duena-ta@gestia.test');

    // Ruta de prueba de un módulo no contratado (Asistencia).
    Route::middleware(['api', 'auth:ayni', 'permission:time.punches.view'])->get('/api/v1/__attendance-probe', fn () => response()->json(['data' => 'ok']));
});

afterEach(fn () => TenancyScope::clear());

function taConsoleUser(string $email, string $role): PlatformUser
{
    return PlatformUser::create([
        'email' => $email, 'full_name' => ucfirst(strtok($email, '@') ?: 'x'), 'role' => $role,
        'status' => 'active', 'password_hash' => Hash::make(TA_PASSWORD),
    ]);
}

function taTenantUser(string $systemRole): User
{
    return TenancyScope::runAs(TA_TENANT, function () use ($systemRole): User {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->firstOrFail()->id]);

        return $user;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function taConsole(?string $token, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $request = test()->flushHeaders();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers['X-XSRF-TOKEN'] = hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'));
    } else {
        // Sin sesión: se descarta la cookie que dejó una llamada anterior de la misma prueba.
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

/**
 * @param  array<string, mixed>  $body
 */
function taApp(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();

    return test()->flushHeaders()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => TA_TENANT])
        ->json($method, '/api/v1'.$path, $body);
}

function taEnrolled(string $email): string
{
    $login = taConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => TA_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) taConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    taConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

/**
 * @return array<string, mixed>
 */
function taNewTenant(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Textiles del Sur', 'slug' => 'textiles-sur', 'country' => 'PE', 'currency' => 'PEN',
        'timezone' => 'America/Lima', 'locale' => 'es', 'trial_ends_on' => now()->addDays(15)->toDateString(),
        'contact_name' => 'Rosa Quispe', 'contact_email' => 'rosa@textiles.test', 'contact_phone' => '+51 999 111 222',
        'admin_name' => 'Rosa Quispe', 'admin_email' => 'rosa@textiles.test',
        'legal_name' => 'Textiles del Sur S.A.C.', 'tax_id' => '20601234567', 'payroll_regime' => 'pe_general',
        'module_codes' => ['time_off'],
    ];
}

test('alta: la organización nace en prueba con su primera empresa, su administrador invitado y sus módulos', function (): void {
    $created = taConsole($this->token, 'POST', '/tenants', taNewTenant());
    $this->assertMatchesOpenApi($created, 'post', '/platform/tenants', 201);
    $created->assertJsonPath('data.status', 'trial')
        ->assertJsonPath('data.trial_ends_on', now()->addDays(15)->toDateString())
        ->assertJsonPath('data.usage.legal_entities', 1)
        ->assertJsonPath('data.usage.users', 1)
        ->assertJsonPath('data.legal_entities.0.legal_name', 'Textiles del Sur S.A.C.');
    $id = (string) $created->json('data.id');

    $modules = collect($created->json('data.modules'))->keyBy('code');
    expect($modules['base']['state'])->toBe('active')
        ->and($modules['core']['state'])->toBe('active')
        ->and($modules['time_off']['state'])->toBe('active')
        ->and($modules['attendance']['state'])->toBe('not_contracted');

    // El administrador queda invitado con el rol de sistema y recibe el enlace en el subdominio.
    TenancyScope::runAs($id, function (): void {
        $user = User::query()->firstOrFail();
        expect($user->status)->toBe(User::STATUS_INVITED);
        expect(DB::table('user_roles AS ur')->join('roles AS r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $user->id)->value('r.system_role_code'))->toBe('admin_empresa');
        expect(DB::table('outbox_events')->where('event_type', 'user.invited.v1')->count())->toBe(1);
    });
    expect(DB::table('tenant_status_events')->where('tenant_id', $id)->value('to_status'))->toBe('trial');
    expect(DB::table('platform_audit_events')->where('action', 'tenant.created')->where('tenant_id', $id)->exists())->toBeTrue();

    $list = taConsole($this->token, 'GET', '/tenants?q=textiles');
    $this->assertMatchesOpenApi($list, 'get', '/platform/tenants', 200);
    expect(collect($list->json('data'))->pluck('slug')->all())->toBe(['textiles-sur']);

    // La ficha: empresas, usuarios, colaboradores y estado de cuenta; nunca sueldos ni fichas (RN-SAS-12).
    $show = taConsole($this->token, 'GET', '/tenants/'.$id);
    $this->assertMatchesOpenApi($show, 'get', '/platform/tenants/{id}', 200);
    expect(array_keys($show->json('data.usage')))->toBe(['legal_entities', 'users', 'active_users', 'employees']);
    expect($show->json('data.account'))->toHaveKeys(['subscription', 'open_balance', 'open_invoices', 'overdue_invoices']);
    expect($show->json('data.allowed_actions'))->toContain('activate', 'cancel');

    // Convertir la prueba en activa.
    $activate = taConsole($this->token, 'POST', '/tenants/'.$id.'/activate');
    $this->assertMatchesOpenApi($activate, 'post', '/platform/tenants/{id}/activate', 200);
    $activate->assertJsonPath('data.status', 'active');
});

test('alta inválida: se valida todo y no queda nada a medias', function (): void {
    $bad = taConsole($this->token, 'POST', '/tenants', taNewTenant(['slug' => 'Con Espacios']));
    $this->assertMatchesOpenApi($bad, 'post', '/platform/tenants', 422);

    taConsole($this->token, 'POST', '/tenants', taNewTenant(['slug' => 'ta-andina']))->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    taConsole($this->token, 'POST', '/tenants', taNewTenant(['payroll_regime' => 'no_existe']))->assertStatus(422);

    // Un RUC inválido lo rechaza el paso de la primera empresa: el alta entera se deshace.
    taConsole($this->token, 'POST', '/tenants', taNewTenant(['tax_id' => '123']))->assertStatus(422);
    expect(DB::table('tenants')->where('slug', 'textiles-sur')->exists())->toBeFalse();
});

test('permisos de la consola: el rol de solo lectura ve pero no crea ni suspende', function (): void {
    taConsoleUser('lectura-ta@gestia.test', 'readonly');
    $token = taEnrolled('lectura-ta@gestia.test');

    taConsole($token, 'GET', '/tenants')->assertOk();
    $create = taConsole($token, 'POST', '/tenants', taNewTenant());
    $this->assertMatchesOpenApi($create, 'post', '/platform/tenants', 403);
    taConsole($token, 'POST', '/tenants/'.TA_TENANT.'/suspend', ['reason' => 'x'])->assertForbidden();
    taConsole($token, 'POST', '/tenants/'.TA_TENANT.'/modules/attendance/activate')->assertForbidden();

    // Sin sesión de consola, 401; una sesión de organización no abre la consola.
    taConsole(null, 'GET', '/tenants')->assertUnauthorized();
});

test('suspender: sus usuarios no ingresan y ven un aviso claro; reactivar sin pérdida de datos', function (): void {
    taApp($this->admin, 'GET', '/legal-entities')->assertOk();

    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/suspend')->assertStatus(422); // sin motivo
    $suspend = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/suspend', ['reason' => 'Falta de pago de octubre']);
    $this->assertMatchesOpenApi($suspend, 'post', '/platform/tenants/{id}/suspend', 200);
    $suspend->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspension_reason', 'Falta de pago de octubre');

    $blocked = taApp($this->admin, 'GET', '/legal-entities');
    $blocked->assertStatus(403)->assertJsonPath('error.code', 'tenant_suspended');
    expect((string) $blocked->json('error.message'))->not->toBe('');

    // Transición inválida: bloquear una organización ya suspendida.
    $invalid = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/block', ['reason' => 'x']);
    $this->assertMatchesOpenApi($invalid, 'post', '/platform/tenants/{id}/block', 409);
    $invalid->assertJsonPath('error.code', 'invalid_transition');

    // Aviso a la organización por el outbox (también por correo: no pueden ingresar a verlo).
    $event = OutboxEvent::query()->where('event_type', 'tenant.status_changed.v1')->where('tenant_id', TA_TENANT)->latest('created_at')->firstOrFail();
    expect($event->payload['to_status'])->toBe('suspended');
    app(NotifyOrganizationStatus::class)->handle($event);
    expect(TenancyScope::runAs(TA_TENANT, fn () => DB::table('notifications')->where('category', 'organization.suspended')->where('recipient_user_id', $this->admin->id)->exists()))->toBeTrue();

    $reactivate = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/reactivate');
    $this->assertMatchesOpenApi($reactivate, 'post', '/platform/tenants/{id}/reactivate', 200);
    taApp($this->admin, 'GET', '/legal-entities')->assertOk();

    // Bloqueo por falta de pago desde activa.
    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/block', ['reason' => 'Deuda vencida'])->assertOk()->assertJsonPath('data.status', 'blocked');
    taApp($this->admin, 'GET', '/legal-entities')->assertStatus(403)->assertJsonPath('error.code', 'tenant_suspended');

    $history = taConsole($this->token, 'GET', '/tenants/'.TA_TENANT.'/status-events');
    $this->assertMatchesOpenApi($history, 'get', '/platform/tenants/{id}/status-events', 200);
    expect(collect($history->json('data'))->pluck('to_status')->all())->toBe(['blocked', 'active', 'suspended']);
    expect(DB::table('platform_audit_events')->where('action', 'tenant.suspend')->where('tenant_id', TA_TENANT)->count())->toBe(1);
});

test('la baja no borra datos: aplica la retención y se puede reactivar mientras dure', function (): void {
    $cancel = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/cancel', ['reason' => 'Cierre del contrato']);
    $this->assertMatchesOpenApi($cancel, 'post', '/platform/tenants/{id}/cancel', 200);
    $days = (int) ((array) config('platform.settings'))['tenants.retention_days_after_cancel']['default'];
    $cancel->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.retention_until', now()->addDays($days)->toDateString());

    taApp($this->admin, 'GET', '/me')->assertStatus(403)->assertJsonPath('error.code', 'tenant_cancelled');
    // Los datos siguen ahí.
    expect(TenancyScope::runAs(TA_TENANT, fn () => DB::table('users')->where('id', $this->admin->id)->exists()))->toBeTrue();

    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/reactivate')->assertOk()->assertJsonPath('data.retention_until', null);
    taApp($this->admin, 'GET', '/me')->assertOk();

    // Vencida la retención ya no se reactiva (RN-SAS-10).
    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/cancel', ['reason' => 'Cierre'])->assertOk();
    DB::table('tenants')->where('id', TA_TENANT)->update(['retention_until' => now()->subDay()->toDateString()]);
    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/reactivate')->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-SAS-10');
});

test('editar datos no cambia el estado: el estado solo cambia por acciones', function (): void {
    $patch = taConsole($this->token, 'PATCH', '/tenants/'.TA_TENANT, ['name' => 'Grupo Andino S.A.', 'contact_email' => 'pagos@andino.test', 'status' => 'cancelled']);
    $this->assertMatchesOpenApi($patch, 'patch', '/platform/tenants/{id}', 200);
    $patch->assertJsonPath('data.name', 'Grupo Andino S.A.')->assertJsonPath('data.status', 'active')->assertJsonPath('data.contact_email', 'pagos@andino.test');
    taConsole($this->token, 'PATCH', '/tenants/'.TA_TENANT, ['timezone' => 'Lima'])->assertStatus(422);
    taConsole($this->token, 'GET', '/tenants/'.Str::uuid())->assertNotFound();
});

test('módulos: una organización con Core HR y Vacaciones activa Asistencia y conserva todos sus datos', function (): void {
    $before = taApp($this->admin, 'GET', '/absence-types');
    $before->assertOk();
    $count = count($before->json('data'));
    expect($count)->toBeGreaterThan(0);

    // No contratado: 403 module_not_enabled y fuera del menú.
    taApp($this->admin, 'GET', '/__attendance-probe')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    expect(taApp($this->admin, 'GET', '/me')->json('data.modules'))->not->toContain('attendance');

    $activate = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/attendance/activate');
    $this->assertMatchesOpenApi($activate, 'post', '/platform/tenants/{id}/modules/{code}/activate', 200);
    expect(collect($activate->json('data'))->firstWhere('code', 'attendance')['state'])->toBe('active');

    taApp($this->admin, 'GET', '/__attendance-probe')->assertOk();
    expect(taApp($this->admin, 'GET', '/me')->json('data.modules'))->toContain('attendance', 'time_off', 'core');
    expect(count(taApp($this->admin, 'GET', '/absence-types')->json('data')))->toBe($count);

    $list = taConsole($this->token, 'GET', '/tenants/'.TA_TENANT.'/modules');
    $this->assertMatchesOpenApi($list, 'get', '/platform/tenants/{id}/modules', 200);
    expect(DB::table('platform_audit_events')->where('action', 'tenant.module_activated')->where('tenant_id', TA_TENANT)->count())->toBe(1);
});

test('módulos: activar uno trae sus dependencias; no se retira uno base ni uno del que dependen otros', function (): void {
    $r = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/advances/activate');
    $r->assertOk();
    expect($r->json('meta.activated'))->toBe(['payroll', 'advances']);

    $core = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/core/deactivate');
    $this->assertMatchesOpenApi($core, 'post', '/platform/tenants/{id}/modules/{code}/deactivate', 422);
    $core->assertJsonPath('error.meta.rule', 'module_core');

    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/time_off/deactivate')->assertStatus(422)->assertJsonPath('error.meta.rule', 'module_required_by');

    $notContracted = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/attendance/deactivate');
    $this->assertMatchesOpenApi($notContracted, 'post', '/platform/tenants/{id}/modules/{code}/deactivate', 409);
    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/no_existe/activate')->assertNotFound();
});

test('retirar un módulo conserva sus datos: solo lectura durante la transición, luego oculto, y vuelve intacto', function (): void {
    $count = count(taApp($this->admin, 'GET', '/absence-types')->json('data'));

    $off = taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/time_off/deactivate');
    $off->assertOk();
    expect(collect($off->json('data'))->firstWhere('code', 'time_off')['state'])->toBe('read_only');

    // En transición: se consulta pero no se modifica.
    taApp($this->admin, 'GET', '/absence-types')->assertOk();
    taApp($this->admin, 'POST', '/absence-types', ['code' => 'nuevo', 'name' => 'Nuevo', 'category' => 'personal'])
        ->assertStatus(403)->assertJsonPath('error.code', 'module_read_only');
    $me = taApp($this->admin, 'GET', '/me');
    expect($me->json('data.modules'))->not->toContain('time_off');
    expect($me->json('data.modules_read_only'))->toBe(['time_off']);

    // Vencida la transición: se oculta.
    TenancyScope::runAs(TA_TENANT, fn () => DB::table('tenant_modules')->where('module_code', 'time_off')->update(['read_only_until' => now()->subMinute()]));
    taApp($this->admin, 'GET', '/absence-types')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');

    // Reactivado: los datos están completos.
    taConsole($this->token, 'POST', '/tenants/'.TA_TENANT.'/modules/time_off/activate')->assertOk();
    expect(count(taApp($this->admin, 'GET', '/absence-types')->json('data')))->toBe($count);
});
