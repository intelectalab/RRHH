<?php

/**
 * SEG-4 · Sesiones y dispositivos. SEG-5 · Política de claves, bloqueo y límite de peticiones.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const SP_TENANT = 'aaaaaaaa-5e62-4000-8000-00000000000a';
const SP_PASSWORD = 'Clave-Segura-2026';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => SP_TENANT, 'slug' => 'sp-org', 'name' => 'Seg P', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [SP_TENANT]);
    RateLimiter::clear('x');
    $this->user = TenancyScope::runAs(SP_TENANT, function (): User {
        $u = User::factory()->withPassword(SP_PASSWORD)->withEmail('rosa@andes.pe')->create(['display_name' => 'Rosa']);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->value('id')]);

        return $u;
    });
    $this->admin = TenancyScope::runAs(SP_TENANT, function (): User {
        $u = User::factory()->withPassword(SP_PASSWORD)->withEmail('admin@andes.pe')->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->value('id')]);

        return $u;
    });
});

afterEach(fn () => TenancyScope::clear());

function spLogin(string $email, string $password, string $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36')
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->withHeaders(['X-Tenant-Id' => SP_TENANT, 'User-Agent' => $ua, 'Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function spAs(string $cookie, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $xsrf = hash_hmac('sha256', 'xsrf|'.$cookie, (string) config('app.key'));

    return test()->withCredentials()->withUnencryptedCookie('ayni_session', $cookie)
        ->withHeaders(['X-Tenant-Id' => SP_TENANT, 'X-XSRF-TOKEN' => $xsrf, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

function spSet(string $code, mixed $value): void
{
    TenancyScope::runAs(SP_TENANT, fn () => DB::table('tenant_settings')->insert(['tenant_id' => SP_TENANT, 'parameter_code' => $code, 'value' => json_encode($value)]));
}

test('el usuario ve sus sesiones con dispositivo y cierra cualquiera', function (): void {
    $pc = spLogin('rosa@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();
    $phone = spLogin('rosa@andes.pe', SP_PASSWORD, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/604.1')->getCookie('ayni_session', false)->getValue();
    // Una sesión abandonada más allá de la inactividad ya no cuenta como abierta.
    spLogin('rosa@andes.pe', SP_PASSWORD, 'Mozilla/5.0 (X11; Linux x86_64) Firefox/131.0');
    TenancyScope::runAs(SP_TENANT, fn () => DB::table('user_sessions')->where('user_id', $this->user->id)->where('user_agent', 'like', '%Firefox%')->update(['last_seen_at' => now()->subHours(3)]));

    $list = spAs($pc, 'GET', '/me/sessions');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/me/sessions', 200);
    $sessions = collect($list->json('data.sessions'));
    expect($sessions)->toHaveCount(2)
        ->and($sessions->firstWhere('current', true)['device'])->toBe('Chrome · Windows')
        ->and($sessions->firstWhere('current', false)['device'])->toBe('Safari · iOS');

    $other = $sessions->firstWhere('current', false)['id'];
    $revoke = spAs($pc, 'POST', '/me/sessions/'.$other.'/revoke');
    $revoke->assertOk();
    $this->assertMatchesOpenApi($revoke, 'post', '/me/sessions/{id}/revoke', 200);
    spAs($phone, 'GET', '/me')->assertStatus(401);
    spAs($pc, 'GET', '/me')->assertOk();
});

test('cambiar la clave aplica la política, la recuerda y cierra las demás sesiones', function (): void {
    $pc = spLogin('rosa@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();
    $phone = spLogin('rosa@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();

    spAs($pc, 'POST', '/me/password', ['current_password' => 'otra', 'password' => 'Nueva-Clave-2027', 'password_confirmation' => 'Nueva-Clave-2027'])
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'current_password');
    $short = spAs($pc, 'POST', '/me/password', ['current_password' => SP_PASSWORD, 'password' => 'corta1', 'password_confirmation' => 'corta1']);
    $short->assertStatus(422);
    $this->assertMatchesOpenApi($short, 'post', '/me/password', 422);
    // La misma clave actual cuenta como repetida.
    spAs($pc, 'POST', '/me/password', ['current_password' => SP_PASSWORD, 'password' => SP_PASSWORD, 'password_confirmation' => SP_PASSWORD])->assertStatus(422);

    $ok = spAs($pc, 'POST', '/me/password', ['current_password' => SP_PASSWORD, 'password' => 'Nueva-Clave-2027', 'password_confirmation' => 'Nueva-Clave-2027']);
    $ok->assertOk();
    $this->assertMatchesOpenApi($ok, 'post', '/me/password', 200);

    spAs($phone, 'GET', '/me')->assertStatus(401);
    spAs($pc, 'GET', '/me')->assertOk();
    TenancyScope::runAs(SP_TENANT, function (): void {
        expect(DB::table('password_history')->where('user_id', $this->user->id)->count())->toBe(2)
            ->and(DB::table('audit_events')->where('action', 'user.password_changed')->count())->toBe(1);
    });
    // La anterior no vuelve a servir (historial).
    spAs($pc, 'POST', '/me/password', ['current_password' => 'Nueva-Clave-2027', 'password' => SP_PASSWORD, 'password_confirmation' => SP_PASSWORD])->assertStatus(422);
});

test('un administrador fuerza el cierre de sesión de un usuario', function (): void {
    $rosa = spLogin('rosa@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();
    $admin = spLogin('admin@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();

    spAs($admin, 'POST', '/users/'.$this->user->id.'/revoke-sessions', [])->assertStatus(422);
    $r = spAs($admin, 'POST', '/users/'.$this->user->id.'/revoke-sessions', ['reason' => 'Equipo extraviado']);
    $r->assertOk();
    $this->assertMatchesOpenApi($r, 'post', '/users/{id}/revoke-sessions', 200);
    spAs($rosa, 'GET', '/me')->assertStatus(401);
    spAs($rosa, 'POST', '/users/'.$this->admin->id.'/revoke-sessions', ['reason' => 'x'])->assertStatus(401);
});

test('la sesión se cierra por inactividad según la organización', function (): void {
    spSet('seguridad.inactividad_minutos', 10);
    $cookie = spLogin('rosa@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();
    $this->travel(9)->minutes();
    spAs($cookie, 'GET', '/me')->assertOk();
    $this->travel(9)->minutes();
    spAs($cookie, 'GET', '/me')->assertOk(); // el uso renueva la inactividad
    $this->travel(11)->minutes();
    spAs($cookie, 'GET', '/me')->assertStatus(401);
});

test('superar el tope de intentos bloquea la cuenta, lo registra y avisa al titular', function (): void {
    spSet('seguridad.intentos_maximos', 3);
    spSet('seguridad.bloqueo_minutos', 20);
    foreach (range(1, 3) as $i) {
        spLogin('rosa@andes.pe', 'clave-equivocada-'.$i)->assertStatus(422);
    }
    // Con la clave correcta tampoco entra mientras dura el bloqueo, y el error no lo revela.
    spLogin('rosa@andes.pe', SP_PASSWORD)->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');

    TenancyScope::runAs(SP_TENANT, function (): void {
        expect(DB::table('users')->where('id', $this->user->id)->value('locked_until'))->not->toBeNull()
            ->and(DB::table('activity_events')->where('user_id', $this->user->id)->where('activity', 'auth.locked')->count())->toBe(1)
            ->and(DB::table('activity_events')->where('user_id', $this->user->id)->where('activity', 'auth.login_failed')->count())->toBe(3)
            ->and(DB::table('login_attempts')->where('user_id', $this->user->id)->where('succeeded', false)->count())->toBe(4)
            ->and(DB::table('notifications')->where('recipient_user_id', $this->user->id)->where('category', 'account.security')->count())->toBe(1);
    });

    $this->travel(21)->minutes();
    spLogin('rosa@andes.pe', SP_PASSWORD)->assertOk();
});

test('superar el límite de peticiones responde 429 con el formato único de error', function (): void {
    config(['identity.api_requests_per_minute_user' => 3]);
    $cookie = spLogin('rosa@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();
    foreach (range(1, 3) as $i) {
        spAs($cookie, 'GET', '/me')->assertOk();
    }
    $r = spAs($cookie, 'GET', '/me');
    $r->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    expect($r->json('error.request_id'))->not->toBeNull();
});

test('la política se edita en Configuración → Seguridad dentro de los márgenes y queda auditada', function (): void {
    $admin = spLogin('admin@andes.pe', SP_PASSWORD)->getCookie('ayni_session', false)->getValue();
    spAs($admin, 'PUT', '/settings/seguridad', ['values' => [['code' => 'seguridad.intentos_maximos', 'value' => 50]]])->assertStatus(422);
    spAs($admin, 'PUT', '/settings/seguridad', ['values' => [['code' => 'seguridad.clave_longitud_minima', 'value' => 14]], 'reason' => 'Política 2027'])->assertOk();
    TenancyScope::runAs(SP_TENANT, fn () => expect(DB::table('audit_events')->where('action', 'setting.changed')->where('reason', 'Política 2027')->count())->toBe(1));

    $login = spLogin('rosa@andes.pe', SP_PASSWORD);
    $login->assertOk();
    $rosa = $login->getCookie('ayni_session', false)->getValue();
    spAs($rosa, 'POST', '/me/password', ['current_password' => SP_PASSWORD, 'password' => 'Clave-Nueva-1', 'password_confirmation' => 'Clave-Nueva-1'])->assertStatus(422);
    spAs($rosa, 'POST', '/me/password', ['current_password' => SP_PASSWORD, 'password' => 'Clave-Nueva-2027x', 'password_confirmation' => 'Clave-Nueva-2027x'])->assertOk();
});
