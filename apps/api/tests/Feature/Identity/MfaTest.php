<?php

/**
 * SEG-3 · Segundo factor (TOTP) y códigos de respaldo; MFA obligatorio por rol (RN-SEG-08, ADR 0009).
 */

use App\Modules\Identity\Application\Mfa\Totp;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const MF_TENANT = 'aaaaaaaa-5e63-4000-8000-00000000000a';
const MF_PASSWORD = 'Clave-Segura-2026';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => MF_TENANT, 'slug' => 'mf-org', 'name' => 'Andes MFA', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [MF_TENANT]);
    $make = fn (string $email, string $role): User => TenancyScope::runAs(MF_TENANT, function () use ($email, $role): User {
        $u = User::factory()->withPassword(MF_PASSWORD)->withEmail($email)->create(['display_name' => ucfirst(strtok($email, '@'))]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
    $this->rosa = $make('rosa@andes.pe', 'empleado');
    $this->admin = $make('admin@andes.pe', 'admin_empresa');
});

afterEach(fn () => TenancyScope::clear());

function mfLogin(string $email, string $password = MF_PASSWORD)
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->withHeaders(['X-Tenant-Id' => MF_TENANT, 'Idempotency-Key' => (string) Str::uuid()])->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

function mfAs(string $cookie, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $xsrf = hash_hmac('sha256', 'xsrf|'.$cookie, (string) config('app.key'));

    return test()->withCredentials()->withUnencryptedCookie('gestia_session', $cookie)
        ->withHeaders(['X-Tenant-Id' => MF_TENANT, 'X-XSRF-TOKEN' => $xsrf, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

function mfCode(string $secret, int $offsetSteps = 0): string
{
    return Totp::at(Totp::base32Decode($secret), Totp::currentStep() + $offsetSteps);
}

/** Configura la aplicación autenticadora y devuelve [secreto, códigos de respaldo]. */
function mfEnroll(string $cookie): array
{
    $start = mfAs($cookie, 'POST', '/me/mfa/totp');
    $start->assertOk();
    $secret = (string) $start->json('data.secret');
    $confirm = mfAs($cookie, 'POST', '/me/mfa/totp/confirm', ['code' => mfCode($secret)]);
    $confirm->assertOk();

    return [$secret, $confirm->json('data.recovery_codes')];
}

test('el usuario activa la aplicación autenticadora y el ingreso pide el segundo paso', function (): void {
    $cookie = mfLogin('rosa@andes.pe')->assertOk()->assertJsonPath('data.mfa_step', 'ok')->getCookie('gestia_session', false)->getValue();

    $start = mfAs($cookie, 'POST', '/me/mfa/totp');
    $start->assertOk();
    $this->assertMatchesOpenApi($start, 'post', '/me/mfa/totp', 200);
    expect($start->json('data.otpauth_uri'))->toStartWith('otpauth://totp/')->toContain('secret='.$start->json('data.secret'));
    $secret = (string) $start->json('data.secret');

    $bad = mfAs($cookie, 'POST', '/me/mfa/totp/confirm', ['code' => '000000']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/me/mfa/totp/confirm', 422);
    $confirm = mfAs($cookie, 'POST', '/me/mfa/totp/confirm', ['code' => mfCode($secret)]);
    $confirm->assertOk();
    $this->assertMatchesOpenApi($confirm, 'post', '/me/mfa/totp/confirm', 200);
    expect($confirm->json('data.recovery_codes'))->toHaveCount(10);

    TenancyScope::runAs(MF_TENANT, function () use ($secret, $confirm): void {
        $factor = DB::table('user_mfa_factors')->where('user_id', $this->rosa->id)->sole();
        // Ni el secreto ni los códigos quedan en claro.
        expect($factor->secret_enc)->not->toContain($secret)
            ->and(DB::table('user_mfa_recovery_codes')->where('user_id', $this->rosa->id)->pluck('code_hash')->all())->not->toContain($confirm->json('data.recovery_codes.0'))
            ->and(DB::table('audit_events')->where('action', 'user.mfa_enabled')->count())->toBe(1);
    });
    $show = mfAs($cookie, 'GET', '/me/mfa');
    $this->assertMatchesOpenApi($show, 'get', '/me/mfa', 200);
    $show->assertJsonPath('data.enabled', true)->assertJsonPath('data.recovery_codes_left', 10)->assertJsonPath('data.step', 'ok');

    // Ingreso nuevo: la clave sola no basta.
    $login = mfLogin('rosa@andes.pe');
    $login->assertOk()->assertJsonPath('data.mfa_step', 'verify');
    $pending = $login->getCookie('gestia_session', false)->getValue();
    $blocked = mfAs($pending, 'GET', '/me/sessions');
    $blocked->assertStatus(401)->assertJsonPath('error.code', 'mfa_required')->assertJsonPath('error.meta.step', 'verify');
    mfAs($pending, 'GET', '/me')->assertOk()->assertJsonPath('data.user.mfa_step', 'verify')->assertJsonPath('data.permissions', []);

    $this->travel(31)->seconds();
    $verify = mfAs($pending, 'POST', '/auth/mfa/verify', ['code' => mfCode($secret)]);
    $verify->assertOk();
    $this->assertMatchesOpenApi($verify, 'post', '/auth/mfa/verify', 200);
    mfAs($pending, 'GET', '/me/sessions')->assertOk();
});

test('un mismo código no sirve dos veces y los fallos cuentan para el bloqueo', function (): void {
    $cookie = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    [$secret] = mfEnroll($cookie);

    // El código con el que se confirmó ya no sirve para ingresar.
    $pending = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    $reused = mfAs($pending, 'POST', '/auth/mfa/verify', ['code' => mfCode($secret)]);
    $reused->assertStatus(422);
    $this->assertMatchesOpenApi($reused, 'post', '/auth/mfa/verify', 422);

    // Volver a ingresar la clave no reinicia el contador de fallos del segundo paso.
    mfAs($pending, 'POST', '/auth/mfa/verify', ['code' => '111111'])->assertStatus(422);
    $again = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfAs($again, 'POST', '/auth/mfa/verify', ['code' => '222222'])->assertStatus(422);
    mfAs($again, 'POST', '/auth/mfa/verify', ['code' => '333333'])->assertStatus(422);
    mfAs($again, 'POST', '/auth/mfa/verify', ['code' => '444444'])->assertStatus(422); // 5.º fallo seguido: bloqueo

    TenancyScope::runAs(MF_TENANT, function (): void {
        expect(DB::table('users')->where('id', $this->rosa->id)->value('locked_until'))->not->toBeNull()
            ->and(DB::table('activity_events')->where('user_id', $this->rosa->id)->where('activity', 'auth.mfa_failed')->count())->toBe(5);
    });
    mfAs($again, 'GET', '/me')->assertStatus(401);
});

test('los códigos de respaldo se muestran una vez y se invalidan al usarse', function (): void {
    $cookie = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    [, $codes] = mfEnroll($cookie);

    $pending = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfAs($pending, 'POST', '/auth/mfa/verify', ['code' => strtoupper($codes[0])])->assertOk()->assertJsonPath('data.recovery_codes_left', 9);

    $other = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfAs($other, 'POST', '/auth/mfa/verify', ['code' => $codes[0]])->assertStatus(422);
    // Ningún endpoint vuelve a mostrar los códigos.
    expect(json_encode(mfAs($pending, 'GET', '/me/mfa')->json()))->not->toContain($codes[1]);

    // Regenerarlos exige la clave e invalida los anteriores.
    mfAs($pending, 'POST', '/me/mfa/recovery-codes', ['password' => 'otra'])->assertStatus(422);
    $new = mfAs($pending, 'POST', '/me/mfa/recovery-codes', ['password' => MF_PASSWORD]);
    $new->assertOk();
    $this->assertMatchesOpenApi($new, 'post', '/me/mfa/recovery-codes', 200);
    mfAs($other, 'POST', '/auth/mfa/verify', ['code' => $codes[1]])->assertStatus(422);
    mfAs($other, 'POST', '/auth/mfa/verify', ['code' => $new->json('data.recovery_codes.0')])->assertOk();
});

test('desactivar exige la clave, queda auditado y no procede si el rol lo exige', function (): void {
    $cookie = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfEnroll($cookie);

    mfAs($cookie, 'POST', '/me/mfa/disable', ['password' => 'otra-clave'])->assertStatus(422)->assertJsonPath('error.details.0.field', 'password');
    $ok = mfAs($cookie, 'POST', '/me/mfa/disable', ['password' => MF_PASSWORD]);
    $ok->assertOk();
    $this->assertMatchesOpenApi($ok, 'post', '/me/mfa/disable', 200);
    TenancyScope::runAs(MF_TENANT, fn () => expect(DB::table('audit_events')->where('action', 'user.mfa_disabled')->where('entity_id', $this->rosa->id)->count())->toBe(1));
    mfLogin('rosa@andes.pe')->assertJsonPath('data.mfa_step', 'ok');

    // El administrador lo trae obligatorio por defecto: no puede apagarlo.
    $admin = mfLogin('admin@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfEnroll($admin);
    $refused = mfAs($admin, 'POST', '/me/mfa/disable', ['password' => MF_PASSWORD]);
    $refused->assertStatus(422)->assertJsonPath('error.meta.rule', 'mfa_required_by_policy');
    $this->assertMatchesOpenApi($refused, 'post', '/me/mfa/disable', 422);
});

test('un rol con MFA obligatorio no puede operar sin configurarlo', function (): void {
    $login = mfLogin('admin@andes.pe');
    $login->assertOk()->assertJsonPath('data.mfa_step', 'enroll');
    $cookie = $login->getCookie('gestia_session', false)->getValue();

    mfAs($cookie, 'GET', '/users')->assertStatus(401)->assertJsonPath('error.meta.step', 'enroll');
    mfAs($cookie, 'GET', '/me/mfa')->assertOk()->assertJsonPath('data.required', true)->assertJsonPath('data.step', 'enroll');
    mfEnroll($cookie);
    mfAs($cookie, 'GET', '/users')->assertOk();
});

test('la organización y los roles definen quién debe usar el segundo factor', function (): void {
    // Toda la organización (opción c del ADR 0009).
    TenancyScope::runAs(MF_TENANT, fn () => DB::table('tenant_settings')->insert(['tenant_id' => MF_TENANT, 'parameter_code' => 'seguridad.mfa_todos', 'value' => 'true']));
    mfLogin('rosa@andes.pe')->assertJsonPath('data.mfa_step', 'enroll');
    TenancyScope::runAs(MF_TENANT, fn () => DB::table('tenant_settings')->where('parameter_code', 'seguridad.mfa_todos')->delete());

    // Un rol (incluso de sistema) se marca con motivo y queda auditado.
    $admin = mfLogin('admin@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfEnroll($admin);
    $roleId = TenancyScope::runAs(MF_TENANT, fn () => Role::query()->where('system_role_code', 'empleado')->value('id'));
    mfAs($admin, 'POST', "/roles/{$roleId}/mfa-requirement", ['required' => true])->assertStatus(422);
    $r = mfAs($admin, 'POST', "/roles/{$roleId}/mfa-requirement", ['required' => true, 'reason' => 'Acceso a boletas']);
    $r->assertOk()->assertJsonPath('data.mfa_required', true);
    $this->assertMatchesOpenApi($r, 'post', '/roles/{id}/mfa-requirement', 200);
    TenancyScope::runAs(MF_TENANT, fn () => expect(DB::table('audit_events')->where('action', 'role.mfa_requirement_changed')->value('reason'))->toBe('Acceso a boletas'));
    mfLogin('rosa@andes.pe')->assertJsonPath('data.mfa_step', 'enroll');

    // Sin el permiso de políticas de seguridad no se cambia.
    $rosa = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfEnroll($rosa);
    mfAs($rosa, 'POST', "/roles/{$roleId}/mfa-requirement", ['required' => false, 'reason' => 'Quiero quitarlo'])->assertStatus(403);
});

test('el algoritmo TOTP coincide con los vectores del RFC 6238', function (): void {
    $key = '12345678901234567890';
    expect(Totp::at($key, intdiv(59, 30)))->toBe('287082')
        ->and(Totp::at($key, intdiv(1111111109, 30)))->toBe('081804')
        ->and(Totp::at($key, intdiv(20000000000, 30)))->toBe('353130')
        ->and(Totp::base32Decode(Totp::base32Encode($key)))->toBe($key)
        ->and(Totp::match(Totp::base32Encode($key), '287082', 59))->toBe(1)
        ->and(Totp::match(Totp::base32Encode($key), '287082', 59 + 90))->toBeNull();
});

test('el administrador restablece el segundo factor de un usuario con motivo', function (): void {
    $rosa = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfEnroll($rosa);
    $admin = mfLogin('admin@andes.pe')->getCookie('gestia_session', false)->getValue();
    mfEnroll($admin);

    $r = mfAs($admin, 'POST', "/users/{$this->rosa->id}/reset-mfa", ['reason' => 'Cambió de teléfono']);
    $r->assertOk()->assertJsonPath('data.mfa_enabled', false);
    mfAs($rosa, 'GET', '/me')->assertStatus(401); // sus sesiones se cerraron
    TenancyScope::runAs(MF_TENANT, function (): void {
        expect(DB::table('user_mfa_recovery_codes')->where('user_id', $this->rosa->id)->count())->toBe(0)
            ->and(DB::table('audit_events')->where('action', 'user.mfa_reset')->value('reason'))->toBe('Cambió de teléfono');
    });
    mfLogin('rosa@andes.pe')->assertJsonPath('data.mfa_step', 'ok');
});

test('abrir la configuración dos veces no invalida el QR y un reloj desfasado se explica', function (): void {
    $cookie = mfLogin('rosa@andes.pe')->getCookie('gestia_session', false)->getValue();
    $first = (string) mfAs($cookie, 'POST', '/me/mfa/totp')->json('data.secret');
    $second = (string) mfAs($cookie, 'POST', '/me/mfa/totp')->json('data.secret');
    expect($second)->toBe($first);

    $skewed = Totp::at(Totp::base32Decode($first), Totp::currentStep() + 10); // 5 minutos adelantado
    mfAs($cookie, 'POST', '/me/mfa/totp/confirm', ['code' => $skewed])->assertStatus(422)
        ->assertJsonPath('error.details.0.message', trans('identity.mfa.clock_skew', ['minutes' => 5]));
    mfAs($cookie, 'POST', '/me/mfa/totp/confirm', ['code' => mfCode($first)])->assertOk();
});
