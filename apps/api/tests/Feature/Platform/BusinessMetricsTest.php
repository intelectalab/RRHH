<?php

/**
 * SAS-10 · Tablero del negocio SaaS: cada indicador sale de suscripciones, organizaciones, consumo medido, módulos,
 * incidencias y la auditoría de la consola, viaja con su definición y se filtra por periodo y por plan. Los ingresos
 * recurrentes van por moneda y se consolidan con los tipos de cambio de la configuración global; una moneda sin tipo
 * se informa aparte.
 */

use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(DatabaseTransactions::class);

const BM_A = 'cccccccc-5a10-4000-8000-00000000000a';
const BM_B = 'cccccccc-5a10-4000-8000-00000000000b';
const BM_C = 'cccccccc-5a10-4000-8000-00000000000c';
const BM_PASSWORD = 'Tablero-Seguro-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([BM_A => 'bm-a', BM_B => 'bm-b', BM_C => 'bm-c'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Org '.$slug, 'status' => $slug === 'bm-c' ? 'trial' : 'active', 'country_code' => 'PE', 'default_currency' => 'PEN', 'created_at' => '2026-10-10 12:00:00']);
    }
    $this->monthly = (string) Str::uuid();
    $this->annual = (string) Str::uuid();
    DB::table('plans')->insert([
        ['id' => $this->monthly, 'code' => 'bm-mensual-'.Str::random(4), 'name' => 'Mensual', 'status' => 'active', 'billing_cycle' => 'monthly', 'price' => '500.00', 'currency' => 'PEN'],
        ['id' => $this->annual, 'code' => 'bm-anual-'.Str::random(4), 'name' => 'Anual', 'status' => 'active', 'billing_cycle' => 'annual', 'price' => '1200.00', 'currency' => 'USD'],
    ]);
    $subA = (string) Str::uuid();
    DB::table('subscriptions')->insert([
        ['id' => $subA, 'tenant_id' => BM_A, 'plan_id' => $this->monthly, 'status' => 'active', 'billing_cycle' => 'monthly', 'starts_on' => '2026-01-01', 'trial_ends_on' => null, 'created_at' => '2026-01-01 00:00:00'],
        ['id' => (string) Str::uuid(), 'tenant_id' => BM_B, 'plan_id' => $this->annual, 'status' => 'active', 'billing_cycle' => 'annual', 'starts_on' => '2026-01-01', 'trial_ends_on' => null, 'created_at' => '2026-01-01 00:00:00'],
        ['id' => (string) Str::uuid(), 'tenant_id' => BM_C, 'plan_id' => $this->monthly, 'status' => 'trialing', 'billing_cycle' => 'monthly', 'starts_on' => '2026-10-10', 'trial_ends_on' => '2026-10-24', 'created_at' => '2026-10-10 12:00:00'],
    ]);
    DB::table('platform_audit_events')->insert(['action' => 'subscription.convert', 'entity_type' => 'subscription', 'entity_id' => $subA, 'tenant_id' => BM_A, 'occurred_at' => '2026-10-12 10:00:00']);
    TenancyScope::runAs(BM_A, fn () => DB::table('tenant_usage_counters')->insert([
        ['tenant_id' => BM_A, 'metric_code' => 'employees', 'period_start' => '2026-10-01', 'value' => 10, 'measured_at' => now()],
        ['tenant_id' => BM_A, 'metric_code' => 'employees', 'period_start' => '2026-10-20', 'value' => 12, 'measured_at' => now()],
    ]));
    PlatformUser::create(['email' => 'tablero@gestia.test', 'full_name' => 'Dueña', 'role' => 'superadmin', 'status' => 'active', 'password_hash' => Hash::make(BM_PASSWORD)]);
    $this->token = bmEnrolled('tablero@gestia.test');
});

afterEach(fn () => TenancyScope::clear());

/**
 * @param  array<string, mixed>  $body
 */
function bmConsole(?string $token, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $request = test()->flushHeaders();
    $headers = ['Idempotency-Key' => (string) Str::uuid(), 'Origin' => 'http://consola.localhost:3000'];
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers['X-XSRF-TOKEN'] = hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'));
    } else {
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function bmEnrolled(string $email): string
{
    $login = bmConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => BM_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) bmConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    bmConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

test('SAS-10 · indicadores reales con definición, por periodo y por plan, y consolidado con los tipos de cambio de la consola', function (): void {
    $all = bmConsole($this->token, 'GET', '/metrics?from=2026-10-01&to=2026-10-31');
    $this->assertMatchesOpenApi($all, 'get', '/platform/metrics', 200);
    $d = $all->json('data');
    $mrr = collect($d['revenue']['mrr'])->keyBy('currency');
    expect($d['tenants']['active'])->toBeGreaterThanOrEqual(2)
        ->and($d['tenants']['new'])->toBeGreaterThanOrEqual(3)
        ->and($mrr->has('PEN'))->toBeTrue()
        ->and($d['subscriptions']['conversions'])->toBeGreaterThanOrEqual(1)
        ->and($d['subscriptions']['trials_started'])->toBeGreaterThanOrEqual(1)
        ->and($d['definitions']['mrr'])->not->toBe('')
        ->and($d['definitions'])->toHaveKeys(['tenants_active', 'arr', 'conversion_rate_pct', 'incidents_open']);

    // Solo el plan anual: un ingreso anual de 1200 USD es 100 al mes; sin tipo de cambio USD no se consolida.
    $annual = bmConsole($this->token, 'GET', '/metrics?from=2026-10-01&to=2026-10-31&plan_id='.$this->annual);
    expect($annual->json('data.revenue.mrr'))->toBe([['amount' => '100.00', 'currency' => 'USD']])
        ->and($annual->json('data.revenue.arr'))->toBe([['amount' => '1200.00', 'currency' => 'USD']])
        ->and($annual->json('data.revenue.consolidated.missing_rates'))->toBe(['USD'])
        ->and($annual->json('data.tenants.total'))->toBe(1);

    bmConsole($this->token, 'PATCH', '/settings/dashboard.exchange_rates', ['value' => ['USD' => '3.75']])->assertOk();
    $converted = bmConsole($this->token, 'GET', '/metrics?plan_id='.$this->annual.'&from=2026-10-01&to=2026-10-31');
    expect($converted->json('data.revenue.consolidated.mrr'))->toBe(['amount' => '375.00', 'currency' => 'PEN'])
        ->and($converted->json('data.revenue.consolidated.missing_rates'))->toBe([]);

    // Plan mensual: una conversión sobre una prueba iniciada en el periodo.
    $monthly = bmConsole($this->token, 'GET', '/metrics?from=2026-10-01&to=2026-10-31&plan_id='.$this->monthly);
    expect($monthly->json('data.subscriptions'))->toMatchArray(['trials_started' => 1, 'conversions' => 1, 'conversion_rate_pct' => '100.0', 'cancellations' => 0])
        ->and($monthly->json('data.employees_managed'))->toBe(12);

    $bad = bmConsole($this->token, 'GET', '/metrics?from=2026-13-40');
    $this->assertMatchesOpenApi($bad, 'get', '/platform/metrics', 400);
    $this->assertMatchesOpenApi(bmConsole(null, 'GET', '/metrics'), 'get', '/platform/metrics', 401);
});
