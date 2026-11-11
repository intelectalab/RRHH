<?php

/**
 * SAS-1 · Consola separada del superadministrador: ingreso propio con segundo factor siempre
 * obligatorio (ADR 0011), roles internos, auditoría propia y frontera con las organizaciones.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const SAS_TENANT = 'aaaaaaaa-5a51-4000-8000-00000000000a';
const SAS_PASSWORD = 'Consola-Segura-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    $this->owner = sasUser('duena@gestia.test', 'superadmin');
});

afterEach(fn () => TenancyScope::clear());

function sasUser(string $email, string $role): PlatformUser
{
    return PlatformUser::create([
        'email' => $email,
        'full_name' => ucfirst(strtok($email, '@') ?: 'x'),
        'role' => $role,
        'status' => 'active',
        'password_hash' => Hash::make(SAS_PASSWORD),
    ]);
}

/**
 * @param  array<string, string>  $headers
 */
function sasCall(?string $token, string $method, string $path, array $body = [], array $headers = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    // Sin cabeceras de llamadas anteriores (X-Tenant-Id de una prueba de organización).
    $request = test()->flushHeaders();
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers += ['X-XSRF-TOKEN' => hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'))];
    } else {
        // Sin sesión: se descarta la cookie que dejó una llamada anterior de la misma prueba.
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers + ['Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1/platform'.$path, $body);
}

function sasLogin(string $email, string $password = SAS_PASSWORD)
{
    return sasCall(null, 'POST', '/auth/login', ['email' => $email, 'password' => $password]);
}

function sasToken($response): string
{
    return (string) $response->getCookie('gestia_console_session', false)?->getValue();
}

function sasCode(string $secret): string
{
    return Totp::at(Totp::base32Decode($secret), Totp::currentStep());
}

/** Ingresa por primera vez y configura el segundo factor; devuelve [token, secreto]. */
function sasEnrolled(string $email): array
{
    $token = sasToken(sasLogin($email)->assertOk());
    $secret = (string) sasCall($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    sasCall($token, 'POST', '/auth/mfa/totp/confirm', ['code' => sasCode($secret)])->assertOk()->assertJsonPath('data.mfa_step', 'ok');

    return [$token, $secret];
}

function sasAudit(string $action): int
{
    return DB::table('platform_audit_events')->where('action', $action)->count();
}

test('primer ingreso: clave, configuración obligatoria del segundo factor y consola', function (): void {
    $login = sasLogin('duena@gestia.test');
    $this->assertMatchesOpenApi($login, 'post', '/platform/auth/login', 200);
    $login->assertJsonPath('data.mfa_step', 'enroll')->assertJsonPath('data.permissions', []);
    $token = sasToken($login);
    expect($token)->not->toBe('');
    // Solo se guarda el hash del token.
    expect(DB::table('platform_sessions')->where('session_hash', $token)->exists())->toBeFalse();

    // La clave sola no opera.
    $blocked = sasCall($token, 'GET', '/metrics');
    $this->assertMatchesOpenApi($blocked, 'get', '/platform/metrics', 401);
    $blocked->assertJsonPath('error.code', 'mfa_required')->assertJsonPath('error.meta.step', 'enroll');

    $me = sasCall($token, 'GET', '/me');
    $this->assertMatchesOpenApi($me, 'get', '/platform/me', 200);
    $me->assertJsonPath('data.user.role', 'superadmin')->assertJsonPath('data.mfa_step', 'enroll');

    $start = sasCall($token, 'POST', '/auth/mfa/totp');
    $this->assertMatchesOpenApi($start, 'post', '/platform/auth/mfa/totp', 200);
    $secret = (string) $start->json('data.secret');
    expect((string) $start->json('data.otpauth_uri'))->toStartWith('otpauth://totp/');
    // El secreto se guarda cifrado.
    expect(PlatformUser::query()->find($this->owner->id)?->mfa_secret_enc)->not->toContain($secret);

    $bad = sasCall($token, 'POST', '/auth/mfa/totp/confirm', ['code' => '000000']);
    $this->assertMatchesOpenApi($bad, 'post', '/platform/auth/mfa/totp/confirm', 422);

    $confirm = sasCall($token, 'POST', '/auth/mfa/totp/confirm', ['code' => sasCode($secret)]);
    $this->assertMatchesOpenApi($confirm, 'post', '/platform/auth/mfa/totp/confirm', 200);
    $confirm->assertJsonPath('data.mfa_step', 'ok');
    expect($confirm->json('data.permissions'))->toContain('platform.audit.view', 'platform.metrics.view');

    $metrics = sasCall($token, 'GET', '/metrics');
    $this->assertMatchesOpenApi($metrics, 'get', '/platform/metrics', 200);
    expect($metrics->json('data.tenants.total'))->toBeInt();
    expect($metrics->json('data.users.active'))->toBeInt();

    $audit = sasCall($token, 'GET', '/audit-events?per_page=50');
    $this->assertMatchesOpenApi($audit, 'get', '/platform/audit-events', 200);
    expect(collect($audit->json('data'))->pluck('action')->all())->toContain('console.login', 'console.mfa_enabled', 'console.login_failed');

    $logout = sasCall($token, 'POST', '/auth/logout');
    $this->assertMatchesOpenApi($logout, 'post', '/platform/auth/logout', 200);
    $after = sasCall($token, 'GET', '/me');
    $this->assertMatchesOpenApi($after, 'get', '/platform/me', 401);

    expect(sasAudit('console.mfa_enabled'))->toBe(1)
        ->and(sasAudit('console.login'))->toBe(1)
        ->and(sasAudit('console.logout'))->toBe(1);
});

test('ingresos siguientes: siempre se pide el código y un código no sirve dos veces', function (): void {
    [, $secret] = sasEnrolled('duena@gestia.test');
    $this->travel(31)->seconds();

    $token = sasToken(sasLogin('duena@gestia.test')->assertOk()->assertJsonPath('data.mfa_step', 'verify'));
    sasCall($token, 'GET', '/audit-events')->assertStatus(401)->assertJsonPath('error.meta.step', 'verify');

    $wrong = sasCall($token, 'POST', '/auth/mfa/verify', ['code' => '123456']);
    $this->assertMatchesOpenApi($wrong, 'post', '/platform/auth/mfa/verify', 422);

    $code = sasCode($secret);
    $ok = sasCall($token, 'POST', '/auth/mfa/verify', ['code' => $code]);
    $this->assertMatchesOpenApi($ok, 'post', '/platform/auth/mfa/verify', 200);
    $ok->assertJsonPath('data.mfa_step', 'ok');

    // Mismo código en otra sesión: rechazado.
    $other = sasToken(sasLogin('duena@gestia.test')->assertOk());
    sasCall($other, 'POST', '/auth/mfa/verify', ['code' => $code])->assertStatus(422);
    // Ya configurado: no se puede reemplazar desde la consola.
    $again = sasCall($other, 'POST', '/auth/mfa/totp');
    $this->assertMatchesOpenApi($again, 'post', '/platform/auth/mfa/totp', 422);
});

test('un usuario de una organización no ingresa a la consola ni ve sus rutas', function (): void {
    DB::table('tenants')->insertOrIgnore(['id' => SAS_TENANT, 'slug' => 'sas-org', 'name' => 'Andes Consola', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [SAS_TENANT]);
    TenancyScope::runAs(SAS_TENANT, fn () => User::factory()->withPassword(SAS_PASSWORD)->withEmail('admin@andes.pe')->create());
    TenancyScope::clear();

    // Sus credenciales no existen en la consola.
    $login = sasLogin('admin@andes.pe');
    $this->assertMatchesOpenApi($login, 'post', '/platform/auth/login', 422);
    $login->assertJsonPath('error.code', 'invalid_credentials');

    // Con la sesión de su organización, la consola no la reconoce.
    app('auth')->forgetGuards();
    $tenantLogin = $this->withHeaders(['X-Tenant-Id' => SAS_TENANT])->postJson('/api/v1/auth/login', ['email' => 'admin@andes.pe', 'password' => SAS_PASSWORD])->assertOk();
    $tenantCookie = (string) $tenantLogin->getCookie('gestia_session', false)?->getValue();
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $me = $this->withCredentials()->withUnencryptedCookie('gestia_session', $tenantCookie)->getJson('/api/v1/platform/me');
    $this->assertMatchesOpenApi($me, 'get', '/platform/me', 401);

    // Desde el host de una organización, la consola no existe (404), ni siquiera con una sesión válida de consola.
    [$token] = sasEnrolled('duena@gestia.test');
    $fromTenant = sasCall(null, 'POST', '/auth/login', ['email' => 'duena@gestia.test', 'password' => SAS_PASSWORD], ['X-Tenant-Id' => SAS_TENANT]);
    $this->assertMatchesOpenApi($fromTenant, 'post', '/platform/auth/login', 404);
    $hidden = sasCall($token, 'GET', '/metrics', [], ['X-Tenant-Id' => SAS_TENANT]);
    $this->assertMatchesOpenApi($hidden, 'get', '/platform/metrics', 404);
});

test('los roles internos limitan lo que cada uno ve (403)', function (): void {
    sasUser('caja@gestia.test', 'billing');
    [$token] = sasEnrolled('caja@gestia.test');

    $me = sasCall($token, 'GET', '/me')->assertOk();
    expect($me->json('data.permissions'))->toContain('platform.metrics.view')->not->toContain('platform.audit.view');

    $this->assertMatchesOpenApi(sasCall($token, 'GET', '/metrics'), 'get', '/platform/metrics', 200);
    $denied = sasCall($token, 'GET', '/audit-events');
    $this->assertMatchesOpenApi($denied, 'get', '/platform/audit-events', 403);
    $denied->assertJsonPath('error.code', 'forbidden');
});

test('datos inválidos responden 422', function (): void {
    $missing = sasCall(null, 'POST', '/auth/login', ['email' => 'no-es-correo']);
    $this->assertMatchesOpenApi($missing, 'post', '/platform/auth/login', 422);
    $missing->assertJsonPath('error.code', 'validation_failed');

    $token = sasToken(sasLogin('duena@gestia.test')->assertOk());
    $this->assertMatchesOpenApi(sasCall($token, 'POST', '/auth/mfa/totp/confirm', []), 'post', '/platform/auth/mfa/totp/confirm', 422);
    // Sin haber iniciado la configuración.
    sasCall($token, 'POST', '/auth/mfa/totp/confirm', ['code' => '123456'])->assertStatus(422)->assertJsonPath('error.meta.rule', 'mfa_enrollment_not_started');
    $this->assertMatchesOpenApi(sasCall($token, 'GET', '/audit-events?per_page=500'), 'get', '/platform/audit-events', 401);
});

test('los intentos fallidos bloquean la cuenta temporalmente', function (): void {
    $max = (int) config('platform.max_failed_attempts');
    for ($i = 0; $i < $max; $i++) {
        sasLogin('duena@gestia.test', 'clave-equivocada')->assertStatus(422);
    }
    // Bloqueada: ni la clave correcta entra, con la misma respuesta.
    sasLogin('duena@gestia.test')->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
    expect(sasAudit('console.account_locked'))->toBe(1);

    $this->travel((int) config('platform.lockout_minutes') + 1)->minutes();
    sasLogin('duena@gestia.test')->assertOk();
});

test('las escrituras con la cookie de la consola exigen su token XSRF', function (): void {
    $token = sasToken(sasLogin('duena@gestia.test')->assertOk());
    $forged = sasCall($token, 'POST', '/auth/mfa/totp', [], ['X-XSRF-TOKEN' => 'otro']);
    $forged->assertStatus(403)->assertJsonPath('error.code', 'csrf_mismatch');
});

test('la auditoría de la consola es de solo inserción y no guarda claves ni códigos', function (): void {
    sasEnrolled('duena@gestia.test');
    $row = DB::table('platform_audit_events')->first();
    expect($row)->not->toBeNull();

    // Dentro de un punto de guardado, para que el error no aborte la transacción de la prueba.
    expect(fn () => DB::transaction(fn () => DB::table('platform_audit_events')->where('id', $row->id)->update(['action' => 'x'])))->toThrow(Exception::class);
    expect(fn () => DB::transaction(fn () => DB::table('platform_audit_events')->where('id', $row->id)->delete()))->toThrow(Exception::class);
    expect(json_encode(DB::table('platform_audit_events')->get()->all()))->not->toContain(SAS_PASSWORD);
});

test('los permisos de cada rol interno existen en el catálogo', function (): void {
    $catalog = DB::table('permissions')->pluck('code')->all();
    foreach ((array) config('platform.roles') as $role => $permissions) {
        expect(in_array($role, PlatformUser::ROLES, true))->toBeTrue();
        foreach ((array) $permissions as $p) {
            expect($p === '*' || in_array($p, $catalog, true))->toBeTrue("{$role}: {$p}");
        }
    }
});

test('el propietario inicial se crea por comando, sin clave en claro en la base', function (): void {
    putenv('PLATFORM_ADMIN_PASSWORD=Otra-Clave-Larga-2026');
    try {
        $this->artisan('platform:create-admin', ['email' => 'Jefe@Gestia.test', 'name' => 'Jefe Consola'])->assertSuccessful();
    } finally {
        putenv('PLATFORM_ADMIN_PASSWORD');
    }
    $user = PlatformUser::query()->where('email', 'jefe@gestia.test')->firstOrFail();
    expect($user->role)->toBe('superadmin')->and($user->password_hash)->not->toContain('Otra-Clave')
        ->and(Hash::check('Otra-Clave-Larga-2026', (string) $user->password_hash))->toBeTrue();
    expect(sasAudit('console.user_created'))->toBe(1);

    // Sin variable: clave generada y mostrada una vez; correo repetido o rol inválido fallan.
    $this->artisan('platform:create-admin', ['email' => 'soporte@gestia.test', 'name' => 'Soporte', '--role' => 'support'])
        ->expectsOutputToContain('Clave generada')->assertSuccessful();
    $this->artisan('platform:create-admin', ['email' => 'jefe@gestia.test', 'name' => 'Otro'])->assertFailed();
    $this->artisan('platform:create-admin', ['email' => 'x@gestia.test', 'name' => 'X', '--role' => 'root'])->assertFailed();
});

test('restablecer el segundo factor desde la terminal cierra las sesiones y obliga a configurarlo', function (): void {
    [$token] = sasEnrolled('duena@gestia.test');
    $this->artisan('platform:reset-mfa', ['email' => 'duena@gestia.test'])->assertSuccessful();

    sasCall($token, 'GET', '/me')->assertStatus(401);
    sasLogin('duena@gestia.test')->assertOk()->assertJsonPath('data.mfa_step', 'enroll');
    expect(sasAudit('console.mfa_reset'))->toBe(1);
});
