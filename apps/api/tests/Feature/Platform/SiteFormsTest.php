<?php

/**
 * WEB-5 a WEB-7 · Formularios públicos del sitio: registro de prueba en tres pasos que crea la organización con el
 * mismo servicio que la consola, demos con horarios libres y enlace para reprogramar, y lista de espera que avisa una
 * sola vez al publicar el módulo. Todo sin sesión y sin organización activa.
 */

use App\Modules\Platform\Application\Web\WebMessages;
use App\Modules\Platform\Domain\Web\Ruc;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(DatabaseTransactions::class);

const SF_PASSWORD = 'Sitio-Seguro-2026!';
const SF_RUC = '20100070970';
const SF_RUC_2 = '20131312955';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    config(['platform.public_form_attempts_per_minute' => 1000]);
    DB::table('plans')->where('code', 'esencial')->update(['status' => 'active', 'is_public' => true, 'trial_days' => 14]);
    config(['platform.settings' => array_merge((array) config('platform.settings'), [
        'web.demo_min_notice_hours' => 24, 'web.demo_slot_minutes' => 60, 'web.demo_days_ahead' => 7,
        'web.demo_day_start' => '09:00', 'web.demo_day_end' => '12:00', 'web.demo_weekdays' => [1, 2, 3, 4, 5],
    ])]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    TenancyScope::clear();
});

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function sfPublic(string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    TenancyScope::clear();

    return test()->flushHeaders()->withHeaders($headers)->json($method, '/api/v1/public'.$path, $body);
}

/**
 * @param  array<string, mixed>  $body
 */
function sfConsole(?string $token, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $request = test()->flushHeaders();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers['X-XSRF-TOKEN'] = hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'));
    } else {
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function sfEnrolled(string $email, string $role): string
{
    PlatformUser::create(['email' => $email, 'full_name' => 'Consola', 'role' => $role, 'status' => 'active', 'password_hash' => Hash::make(SF_PASSWORD)]);
    $login = sfConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => SF_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) sfConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    sfConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

/**
 * @return list<string>
 */
function sfFields(TestResponse $response): array
{
    return array_values(array_unique(collect($response->json('error.details'))->pluck('field')->all()));
}

/** El código de verificación solo viaja en el correo encolado. */
function sfCode(string $email): string
{
    $body = (string) DB::table('web_messages')->where('kind', 'signup_code')->where('to_email', $email)->orderByDesc('created_at')->value('body');
    preg_match('/\b(\d{6})\b/', $body, $m);

    return $m[1] ?? '';
}

/**
 * @return array{token: string, email: string}
 */
function sfStartSignup(string $email): array
{
    $res = sfPublic('POST', '/signups', ['full_name' => 'Ana Torres', 'email' => $email, 'consent' => true, 'source_page' => '/precios', 'utm' => ['campaign' => 'lanzamiento']])->assertCreated();

    return ['token' => (string) $res->json('data.token'), 'email' => $email];
}

test('WEB-5 · RUC con dígito verificador y subdominio disponible', function (): void {
    expect(Ruc::isValid(SF_RUC))->toBeTrue()
        ->and(Ruc::isValid('20100070971'))->toBeFalse()
        ->and(Ruc::isValid('30100070970'))->toBeFalse()
        ->and(Ruc::isValid('2010007097'))->toBeFalse();

    $res = sfPublic('GET', '/ruc/'.SF_RUC)->assertOk();
    $this->assertMatchesOpenApi($res, 'get', '/public/ruc/{ruc}', 200);
    expect($res->json('data'))->toMatchArray(['valid' => true, 'registered' => false]);

    expect(sfPublic('GET', '/subdomains/mi-empresa')->assertOk()->json('data.available'))->toBeTrue()
        ->and(sfPublic('GET', '/subdomains/api')->json('data.reason'))->toBe('reserved')
        ->and(sfPublic('GET', '/subdomains/-mal')->json('data.reason'))->toBe('invalid');
});

test('WEB-5 · registro en tres pasos crea la organización igual que la consola y repetir no crea otra', function (): void {
    ['token' => $token, 'email' => $email] = sfStartSignup('ana@andina.test');
    $this->assertMatchesOpenApi(sfPublic('PATCH', '/signups/'.$token, ['verification_code' => '000000']), 'patch', '/public/signups/{token}', 422);

    // Sin correo confirmado no se avanza.
    sfPublic('PATCH', '/signups/'.$token, ['plan' => 'esencial'])->assertStatus(422)->assertJsonPath('error.meta.rule', 'email_not_verified');

    $verified = sfPublic('PATCH', '/signups/'.$token, ['verification_code' => sfCode($email)])->assertOk();
    $this->assertMatchesOpenApi($verified, 'patch', '/public/signups/{token}', 200);
    expect($verified->json('data'))->toMatchArray(['email_verified' => true, 'step' => 'company']);

    expect(sfFields(sfPublic('PATCH', '/signups/'.$token, ['company' => [
        'ruc' => '20100070971', 'company_name' => 'Andina SAC', 'employees_range' => '11-50', 'labor_regime' => 'pe_general', 'subdomain' => 'andina',
    ]])->assertStatus(422)))->toBe(['company.ruc']);
    sfPublic('PATCH', '/signups/'.$token, ['company' => [
        'ruc' => SF_RUC, 'company_name' => 'Andina SAC', 'employees_range' => '11-50', 'labor_regime' => 'pe_general', 'subdomain' => 'andina',
    ]])->assertOk()->assertJsonPath('data.step', 'plan');
    sfPublic('PATCH', '/signups/'.$token, ['plan' => 'esencial'])->assertOk()->assertJsonPath('data.plan', 'esencial');

    $done = sfPublic('POST', '/signups/'.$token.'/complete', ['mode' => 'trial'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    $this->assertMatchesOpenApi($done, 'post', '/public/signups/{token}/complete', 200);
    expect($done->json('data.step'))->toBe('done')->and($done->json('data.tenant.slug'))->toBe('andina');

    $tenant = DB::table('tenants')->where('slug', 'andina')->first();
    expect($tenant->status)->toBe('trial')->and($tenant->contact_email)->toBe($email);
    $entity = app(CrossTenantMaintenance::class)->readAcrossTenants(fn () => DB::table('legal_entities')->where('tenant_id', $tenant->id)->first(['tax_id']));
    expect($entity->tax_id)->toBe(SF_RUC);
    expect(DB::table('subscriptions')->where('tenant_id', $tenant->id)->value('status'))->toBe('trialing');
    $lead = DB::table('web_leads')->where('kind', 'signup')->where('email', $email)->first();
    expect($lead->status)->toBe('converted')->and($lead->tenant_id)->toBe($tenant->id)->and($lead->consent_purpose)->toBe('signup');
    expect(DB::table('platform_audit_events')->where('action', 'web.signup_completed')->where('tenant_id', $tenant->id)->exists())->toBeTrue();

    // Repetir el completar devuelve la misma organización.
    sfPublic('POST', '/signups/'.$token.'/complete')->assertOk()->assertJsonPath('data.tenant.slug', 'andina');
    expect(DB::table('tenants')->where('slug', 'andina')->count())->toBe(1);

    // RN-WEB-06: el mismo RUC o subdominio se rechazan antes de crear nada.
    ['token' => $other, 'email' => $otherEmail] = sfStartSignup('luis@otra.test');
    sfPublic('PATCH', '/signups/'.$other, ['verification_code' => sfCode($otherEmail)])->assertOk();
    expect(sfFields(sfPublic('PATCH', '/signups/'.$other, ['company' => [
        'ruc' => SF_RUC, 'company_name' => 'Otra SAC', 'employees_range' => '1-10', 'labor_regime' => 'pe_general', 'subdomain' => 'andina',
    ]])->assertStatus(422)))->toBe(['company.ruc', 'company.subdomain']);
    expect(sfPublic('GET', '/ruc/'.SF_RUC)->json('data.registered'))->toBeTrue();
});

test('WEB-5 · antiabuso, idempotencia del primer paso y registro a medias visible en la consola', function (): void {
    sfPublic('POST', '/signups', ['full_name' => 'Bot', 'email' => 'bot@x.test', 'consent' => true, 'website' => 'http://spam'])->assertStatus(422)->assertJsonPath('error.code', 'spam_detected');
    expect(sfFields(sfPublic('POST', '/signups', ['full_name' => 'Bot', 'email' => 'bot@x.test', 'consent' => true, 'captcha_token' => 'fail'])->assertStatus(422)))->toBe(['captcha_token']);
    expect(sfFields(sfPublic('POST', '/signups', ['full_name' => 'Bot', 'email' => 'bot@x.test', 'consent' => false])->assertStatus(422)))->toBe(['consent']);
    expect(DB::table('web_signups')->where('email', 'bot@x.test')->exists())->toBeFalse();

    $key = (string) Str::uuid();
    $first = sfPublic('POST', '/signups', ['full_name' => 'Rosa', 'email' => 'rosa@x.test', 'consent' => true], ['Idempotency-Key' => $key])->assertCreated();
    $this->assertMatchesOpenApi($first, 'post', '/public/signups', 201);
    // Repetir con la misma clave no crea otro registro; el token anterior deja de servir.
    $replay = sfPublic('POST', '/signups', ['full_name' => 'Rosa', 'email' => 'rosa@x.test', 'consent' => true], ['Idempotency-Key' => $key])->assertCreated();
    expect(DB::table('web_signups')->where('email', 'rosa@x.test')->count())->toBe(1)->and($replay->json('data.id'))->toBe($first->json('data.id'));
    sfPublic('PATCH', '/signups/'.$first->json('data.token'), ['resend_code' => true])->assertNotFound();

    // El código vence a los intentos.
    for ($i = 0; $i < 5; $i++) {
        sfPublic('PATCH', '/signups/'.$replay->json('data.token'), ['verification_code' => '999999']);
    }
    $second = sfPublic('POST', '/signups', ['full_name' => 'Rosa', 'email' => 'rosa@x.test', 'consent' => true], ['Idempotency-Key' => $key])->json('data.token');
    sfPublic('PATCH', '/signups/'.$second, ['verification_code' => sfCode('rosa@x.test')])->assertStatus(429);

    $console = sfEnrolled('ventas@gestiahr.test', 'superadmin');
    $list = sfConsole($console, 'GET', '/web-leads?filter[kind]=signup')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/platform/web-leads', 200);
    $row = collect($list->json('data'))->firstWhere('email', 'rosa@x.test');
    expect($row['signup_step'])->toBe('account')->and($row['status'])->toBe('new');

    // RN-WEB-07: pasada la retención, el registro a medias y su lead se borran.
    $this->artisan('web:run --purge')->assertSuccessful();
    expect(DB::table('web_signups')->where('email', 'rosa@x.test')->exists())->toBeTrue();
    Carbon::setTestNow(now()->addDays(200));
    $this->artisan('web:run --purge')->assertSuccessful();
    expect(DB::table('web_signups')->where('email', 'rosa@x.test')->exists())->toBeFalse()
        ->and(DB::table('web_leads')->where('email', 'rosa@x.test')->exists())->toBeFalse();
});

test('WEB-6 · demo solo en horarios libres con anticipación, enlace para reprogramar o cancelar y un recordatorio', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-12-07 08:00', 'America/Lima'));
    $slots = sfPublic('GET', '/demo-slots')->assertOk();
    $this->assertMatchesOpenApi($slots, 'get', '/public/demo-slots', 200);
    $starts = collect($slots->json('data'))->pluck('starts_at');
    // El lunes 7 a las 9:00 queda a menos de 24 horas: el primero es el martes.
    expect($starts->first())->toBe('2026-12-08T09:00:00-05:00')->and($starts->contains('2026-12-12T09:00:00-05:00'))->toBeFalse();

    $booked = sfPublic('POST', '/demo-bookings', [
        'starts_at' => '2026-12-08T09:00:00-05:00', 'full_name' => 'Pedro Ríos', 'email' => 'pedro@x.test', 'company_name' => 'Ríos SAC',
        'consent' => true, 'consent_purpose' => 'demo', 'source_page' => '/demo', 'utm' => ['source' => 'google'],
    ])->assertCreated();
    $this->assertMatchesOpenApi($booked, 'post', '/public/demo-bookings', 201);
    $token = (string) $booked->json('data.manage_token');
    $lead = DB::table('web_leads')->where('kind', 'demo')->where('email', 'pedro@x.test')->first();
    expect($lead->source_page)->toBe('/demo')->and(json_decode((string) $lead->utm, true))->toBe(['source' => 'google'])->and($lead->consent_at)->not->toBeNull();
    expect(DB::table('web_messages')->where('kind', 'demo_booked')->where('to_email', 'pedro@x.test')->value('body'))->toContain('/demo/gestionar?t='.$token);

    // El horario tomado ya no se ofrece ni se puede tomar.
    expect(collect(sfPublic('GET', '/demo-slots')->json('data'))->pluck('starts_at')->contains('2026-12-08T09:00:00-05:00'))->toBeFalse();
    $taken = sfPublic('POST', '/demo-bookings', ['starts_at' => '2026-12-08T09:00:00-05:00', 'full_name' => 'Otra', 'email' => 'o@x.test', 'consent' => true]);
    $taken->assertStatus(409);
    $this->assertMatchesOpenApi($taken, 'post', '/public/demo-bookings', 409);

    $this->assertMatchesOpenApi(sfPublic('GET', '/demo-bookings/'.$token)->assertOk(), 'get', '/public/demo-bookings/{token}', 200);
    $moved = sfPublic('POST', '/demo-bookings/'.$token.'/cancel', ['action' => 'reschedule', 'starts_at' => '2026-12-09T10:00:00-05:00'])->assertOk();
    $this->assertMatchesOpenApi($moved, 'post', '/public/demo-bookings/{token}/cancel', 200);
    expect($moved->json('data.status'))->toBe('rescheduled');

    // Un recordatorio el día anterior, una sola vez.
    Carbon::setTestNow(Carbon::parse('2026-12-08 12:00', 'America/Lima'));
    $this->artisan('web:run')->assertSuccessful();
    $this->artisan('web:run')->assertSuccessful();
    expect(DB::table('web_messages')->where('kind', 'demo_reminder')->where('to_email', 'pedro@x.test')->count())->toBe(1);

    sfPublic('POST', '/demo-bookings/'.$token.'/cancel', ['action' => 'cancel'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    sfPublic('POST', '/demo-bookings/'.$token.'/cancel', ['action' => 'cancel'])->assertStatus(409);
    sfPublic('GET', '/demo-bookings/no-existe')->assertNotFound();

    $console = sfEnrolled('demos@gestiahr.test', 'superadmin');
    $list = sfConsole($console, 'GET', '/web-demo-bookings')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/platform/web-demo-bookings', 200);
    expect(collect($list->json('data'))->firstWhere('email', 'pedro@x.test')['status'])->toBe('cancelled');
});

test('WEB-7 · lista de espera de lo «Próximamente» avisa una sola vez al publicar el módulo', function (): void {
    DB::table('modules')->where('code', 'payroll')->update(['is_published' => false, 'published_at' => null]);
    DB::table('modules')->where('code', 'core')->update(['is_published' => true]);

    sfPublic('POST', '/leads', ['kind' => 'waitlist', 'module_code' => 'core', 'email' => 'x@x.test', 'consent' => true, 'consent_purpose' => 'waitlist'])
        ->assertStatus(422)->assertJsonPath('error.meta.rule', 'module_available');
    $joined = sfPublic('POST', '/leads', ['kind' => 'waitlist', 'module_code' => 'payroll', 'email' => 'ana@w.test', 'full_name' => 'Ana', 'consent' => true, 'consent_purpose' => 'waitlist', 'source_page' => '/planilla'])->assertCreated();
    $this->assertMatchesOpenApi($joined, 'post', '/public/leads', 201);
    // Inscribirse dos veces no duplica.
    sfPublic('POST', '/leads', ['kind' => 'waitlist', 'module_code' => 'payroll', 'email' => 'ana@w.test', 'consent' => true, 'consent_purpose' => 'waitlist'])->assertCreated();
    sfPublic('POST', '/leads', ['kind' => 'waitlist', 'module_code' => 'payroll', 'email' => 'luis@w.test', 'consent' => true, 'consent_purpose' => 'waitlist'])->assertCreated();
    sfPublic('POST', '/leads', ['kind' => 'contact', 'email' => 'c@w.test', 'message' => 'Hola', 'consent' => true, 'consent_purpose' => 'contacto comercial'])->assertCreated();

    $console = sfEnrolled('wait@gestiahr.test', 'superadmin');
    $waitlist = sfConsole($console, 'GET', '/web-waitlist')->assertOk();
    $this->assertMatchesOpenApi($waitlist, 'get', '/platform/web-waitlist', 200);
    expect(collect($waitlist->json('data'))->firstWhere('module_code', 'payroll'))->toMatchArray(['waiting' => 2, 'notified' => 0]);

    sfConsole($console, 'POST', '/modules/payroll/publish')->assertOk();
    sfConsole($console, 'POST', '/modules/payroll/unpublish')->assertOk();
    sfConsole($console, 'POST', '/modules/payroll/publish')->assertOk();
    expect(DB::table('web_messages')->where('kind', 'waitlist_launch')->count())->toBe(2);
    expect(collect(sfConsole($console, 'GET', '/web-waitlist')->json('data'))->firstWhere('module_code', 'payroll'))->toMatchArray(['waiting' => 0, 'notified' => 2]);

    // Los correos salen por el puerto de correo, una vez cada uno.
    app(WebMessages::class)->deliver();
    expect(DB::table('web_messages')->where('kind', 'waitlist_launch')->where('status', 'sent')->count())->toBe(2)
        ->and(app(WebMessages::class)->deliver())->toBe(0);
});
