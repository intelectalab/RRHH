<?php

/**
 * SEG-1 · Ingreso con correo y contraseña, recuperación de clave y cierre de sesión.
 */

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Modules\Identity\Infrastructure\Models\UserToken;
use App\Shared\Integrations\Fake\FakeEmailAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Tenancy\TenantContextRequiredException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

const SEG_TENANT_A = 'aaaaaaaa-5e61-4000-8000-00000000000a';
const SEG_TENANT_B = 'bbbbbbbb-5e61-4000-8000-00000000000b';
const SEG_PASSWORD = 'Clave-Segura-2026';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => SEG_TENANT_A, 'slug' => 'seg-org-a', 'name' => 'Seg A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => SEG_TENANT_B, 'slug' => 'seg-org-b', 'name' => 'Seg B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);

    $this->user = TenancyScope::runAs(SEG_TENANT_A, fn () => User::factory()
        ->withPassword(SEG_PASSWORD)
        ->withEmail('ana.quispe@empresa-a.pe')
        ->create(['display_name' => 'Ana Quispe']));
});

afterEach(function (): void {
    TenancyScope::clear();
});

function segLogin(string $email, string $password, string $tenant = SEG_TENANT_A)
{
    TenancyScope::clear();

    return test()->withHeaders(['X-Tenant-Id' => $tenant])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

test('el ingreso correcto abre una sesión con cookie HttpOnly y XSRF', function (): void {
    $response = segLogin('Ana.Quispe@Empresa-A.pe', SEG_PASSWORD);

    $response->assertOk()->assertJsonPath('data.user.display_name', 'Ana Quispe');
    $this->assertMatchesOpenApi($response, 'post', '/auth/login', 200);

    $session = $response->getCookie('gestia_session', false);
    $xsrf = $response->getCookie('XSRF-TOKEN', false);
    expect($session)->not->toBeNull()
        ->and($session->isHttpOnly())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax')
        ->and($xsrf->isHttpOnly())->toBeFalse();

    $stored = TenancyScope::runAs(SEG_TENANT_A, fn () => UserSession::query()->sole());
    expect($stored->session_hash)->toBe(hash('sha256', $session->getValue()))
        ->and($stored->session_hash)->not->toBe($session->getValue());
});

test('la clave se guarda con argon2id y nunca en claro', function (): void {
    $hash = TenancyScope::runAs(SEG_TENANT_A, fn () => DB::table('users')->where('id', $this->user->id)->value('password_hash'));

    expect($hash)->toStartWith('$argon2id$')->not->toContain(SEG_PASSWORD);
});

test('la clave no aparece en los logs aunque el ingreso falle', function (): void {
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });

    segLogin('ana.quispe@empresa-a.pe', 'clave-equivocada-xyz')->assertStatus(422);
    segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->assertOk();

    expect(implode("\n", $logged))->not->toContain('clave-equivocada-xyz')->not->toContain(SEG_PASSWORD);
});

test('el error no revela si el correo existe', function (): void {
    $wrongPassword = segLogin('ana.quispe@empresa-a.pe', 'otra-clave-123');
    $unknownEmail = segLogin('nadie@empresa-a.pe', 'otra-clave-123');

    $wrongPassword->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
    $unknownEmail->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
    expect($wrongPassword->json('error.message'))->toBe($unknownEmail->json('error.message'))
        ->and($wrongPassword->json('error.details'))->toBe($unknownEmail->json('error.details'));
    $this->assertMatchesOpenApi($wrongPassword, 'post', '/auth/login', 422);
});

test('un intento fallido incrementa el contador y queda en la actividad', function (): void {
    segLogin('ana.quispe@empresa-a.pe', 'otra-clave-123')->assertStatus(422);

    TenancyScope::runAs(SEG_TENANT_A, function (): void {
        expect(DB::table('users')->where('id', $this->user->id)->value('failed_attempts'))->toBe(1)
            ->and(DB::table('activity_events')->where('activity', 'auth.login_failed')->count())->toBe(1);
    });
});

test('una cuenta suspendida o invitada no ingresa', function (string $status): void {
    TenancyScope::runAs(SEG_TENANT_A, fn () => DB::table('users')->where('id', $this->user->id)->update(['status' => $status]));

    segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->assertStatus(422)->assertJsonPath('error.code', 'invalid_credentials');
})->with(['disabled', 'invited', 'locked']);

test('el mismo correo y clave no sirven en otra organización', function (): void {
    segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD, SEG_TENANT_B)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

test('la cookie de una organización no autentica en otra', function (): void {
    $cookie = segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->getCookie('gestia_session', false)->getValue();
    $xsrf = hash_hmac('sha256', 'xsrf|'.$cookie, (string) config('app.key'));

    TenancyScope::clear();
    $this->withCredentials()->withUnencryptedCookie('gestia_session', $cookie)
        ->withHeaders(['X-Tenant-Id' => SEG_TENANT_B, 'X-XSRF-TOKEN' => $xsrf])
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401);
});

test('validación: falta la clave → 422 validation_failed', function (): void {
    $response = $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])->postJson('/api/v1/auth/login', ['email' => 'ana.quispe@empresa-a.pe']);

    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($response, 'post', '/auth/login', 422);
});

test('el ingreso sin organización en el host responde 400', function (): void {
    $this->postJson('/api/v1/auth/login', ['email' => 'ana.quispe@empresa-a.pe', 'password' => SEG_PASSWORD])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'missing_tenant_context');
});

test('cerrar sesión revoca la sesión y borra las cookies', function (): void {
    $cookie = segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->getCookie('gestia_session', false)->getValue();
    $xsrf = hash_hmac('sha256', 'xsrf|'.$cookie, (string) config('app.key'));

    $response = $this->withCredentials()->withUnencryptedCookie('gestia_session', $cookie)
        ->withHeaders(['X-Tenant-Id' => SEG_TENANT_A, 'X-XSRF-TOKEN' => $xsrf])
        ->postJson('/api/v1/auth/logout');

    $response->assertOk();
    $this->assertMatchesOpenApi($response, 'post', '/auth/logout', 200);
    expect($response->getCookie('gestia_session', false)->getValue())->toBe('');
    TenancyScope::runAs(SEG_TENANT_A, fn () => expect(UserSession::query()->sole()->revoked_at)->not->toBeNull());

    // La misma cookie ya no sirve (petición nueva: guard sin estado previo).
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $this->withCredentials()->withUnencryptedCookie('gestia_session', $cookie)
        ->withHeaders(['X-Tenant-Id' => SEG_TENANT_A, 'X-XSRF-TOKEN' => $xsrf])
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401);
});

test('cerrar sesión sin cabecera XSRF responde 403 csrf_mismatch', function (): void {
    $cookie = segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->getCookie('gestia_session', false)->getValue();

    TenancyScope::clear();
    $this->withCredentials()->withUnencryptedCookie('gestia_session', $cookie)
        ->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'csrf_mismatch');
});

test('cerrar sesión sin sesión responde 401', function (): void {
    $response = $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])->postJson('/api/v1/auth/logout');

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    $this->assertMatchesOpenApi($response, 'post', '/auth/logout', 401);
});

test('el ingreso tiene límite de intentos por minuto', function (): void {
    config()->set('identity.login_attempts_per_minute', 3);
    foreach (range(1, 3) as $_) {
        segLogin('ana.quispe@empresa-a.pe', 'mala-clave-000')->assertStatus(422);
    }

    $response = segLogin('ana.quispe@empresa-a.pe', 'mala-clave-000');
    $response->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    $this->assertMatchesOpenApi($response, 'post', '/auth/login', 429);
});

/*
 * Recuperación de clave.
 */

function segForgot(string $email)
{
    TenancyScope::clear();

    return test()->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])->postJson('/api/v1/auth/password/forgot', ['email' => $email]);
}

function segIssuedToken(): string
{
    return TenancyScope::runAs(SEG_TENANT_A, function (): string {
        /** @var OutboxEvent $event */
        $event = OutboxEvent::query()->where('event_type', DomainEventTypes::USER_PASSWORD_RESET_REQUESTED)->latest('occurred_at')->firstOrFail();

        return Crypt::decryptString($event->payload['token_enc']);
    });
}

test('la solicitud de recuperación responde igual exista o no el correo', function (): void {
    $known = segForgot('ana.quispe@empresa-a.pe');
    $unknown = segForgot('nadie@empresa-a.pe');

    $known->assertOk();
    $this->assertMatchesOpenApi($known, 'post', '/auth/password/forgot', 200);
    expect($known->json())->toBe($unknown->json());
    TenancyScope::runAs(SEG_TENANT_A, fn () => expect(UserToken::query()->count())->toBe(1));
});

test('el token se guarda solo como hash y el envío sale por el outbox con el token cifrado', function (): void {
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $raw = segIssuedToken();

    TenancyScope::runAs(SEG_TENANT_A, function () use ($raw): void {
        $token = UserToken::query()->sole();
        $event = OutboxEvent::query()->where('event_type', DomainEventTypes::USER_PASSWORD_RESET_REQUESTED)->sole();

        expect($token->token_hash)->toBe(hash('sha256', $raw))
            ->and(json_encode($event->payload))->not->toContain($raw);
    });
});

test('el suscriptor envía el enlace por el puerto de correo falso', function (): void {
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $raw = segIssuedToken();
    $fake = new FakeEmailAdapter;
    app(IntegrationManager::class)->setEmailAdapter($fake);

    $event = TenancyScope::runAs(SEG_TENANT_A, fn () => OutboxEvent::query()->where('event_type', DomainEventTypes::USER_PASSWORD_RESET_REQUESTED)->sole());
    foreach (app(OutboxService::class)->getSubscribersFor($event->event_type) as $subscriber) {
        $subscriber->handle($event);
    }

    $sent = $fake->getSent();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['to'])->toBe('ana.quispe@empresa-a.pe')
        ->and($sent[0]['body'])->toContain('/restablecer-clave?token='.$raw);
});

test('el enlace restablece la clave, es de un solo uso y cierra las sesiones', function (): void {
    segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->assertOk();
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $raw = segIssuedToken();
    $new = 'Nueva-Clave-2026x';

    TenancyScope::clear();
    $response = $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])
        ->postJson('/api/v1/auth/password/reset', ['token' => $raw, 'password' => $new, 'password_confirmation' => $new]);
    $response->assertOk();
    $this->assertMatchesOpenApi($response, 'post', '/auth/password/reset', 200);

    TenancyScope::runAs(SEG_TENANT_A, fn () => expect(UserSession::query()->whereNull('revoked_at')->count())->toBe(0));
    segLogin('ana.quispe@empresa-a.pe', SEG_PASSWORD)->assertStatus(422);
    segLogin('ana.quispe@empresa-a.pe', $new)->assertOk();

    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])
        ->postJson('/api/v1/auth/password/reset', ['token' => $raw, 'password' => 'Otra-Clave-2026y', 'password_confirmation' => 'Otra-Clave-2026y'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_token');
});

test('el enlace caduca', function (): void {
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $raw = segIssuedToken();
    $this->travel((int) config('identity.password_reset_ttl_minutes') + 1)->minutes();

    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])
        ->postJson('/api/v1/auth/password/reset', ['token' => $raw, 'password' => 'Nueva-Clave-2026x', 'password_confirmation' => 'Nueva-Clave-2026x'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_token');
});

test('un nuevo enlace invalida el anterior', function (): void {
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $first = segIssuedToken();
    $this->travel(1)->seconds();
    segForgot('ana.quispe@empresa-a.pe')->assertOk();

    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])
        ->postJson('/api/v1/auth/password/reset', ['token' => $first, 'password' => 'Nueva-Clave-2026x', 'password_confirmation' => 'Nueva-Clave-2026x'])
        ->assertStatus(422);
});

test('el enlace de una organización no sirve en otra', function (): void {
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $raw = segIssuedToken();

    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_B])
        ->postJson('/api/v1/auth/password/reset', ['token' => $raw, 'password' => 'Nueva-Clave-2026x', 'password_confirmation' => 'Nueva-Clave-2026x'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_token');
});

test('una clave débil se rechaza con 422', function (): void {
    segForgot('ana.quispe@empresa-a.pe')->assertOk();
    $raw = segIssuedToken();

    TenancyScope::clear();
    $response = $this->withHeaders(['X-Tenant-Id' => SEG_TENANT_A])
        ->postJson('/api/v1/auth/password/reset', ['token' => $raw, 'password' => 'corta', 'password_confirmation' => 'corta']);
    $response->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($response, 'post', '/auth/password/reset', 422);
});

test('el modelo User exige organización activa', function (): void {
    TenancyScope::clear();

    expect(fn () => User::query()->count())->toThrow(TenantContextRequiredException::class);
    expect(Route::has('login'))->toBeFalse();
});
