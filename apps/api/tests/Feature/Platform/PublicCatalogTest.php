<?php

/**
 * WEB-2 · WEB-4 · Catálogo y cotización públicos del sitio gestiahr.com: lo que se muestra sale de la consola
 * (planes, precios, complementos y módulos a la venta), sin montos en el código ni datos de organizaciones.
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

const PC_PASSWORD = 'Catalogo-Seguro-2026!';
const PC_TENANT = 'cccccccc-0eb1-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    // Precios de prueba cargados como lo haría la consola (no hay montos en el código).
    DB::table('plans')->where('code', 'esencial')->update(['status' => 'active', 'price' => '100.00', 'currency' => 'PEN', 'billing_cycle' => 'monthly']);
    DB::table('plans')->where('code', 'profesional')->update(['status' => 'active', 'price' => null, 'currency' => null]);
    DB::table('addons')->where('code', 'planilla')->update(['status' => 'active', 'price' => '3.50', 'currency' => 'PEN']);
    DB::table('addons')->where('code', 'talento')->update(['status' => 'active', 'price' => '2.00', 'currency' => 'PEN']);
    DB::table('modules')->whereIn('code', ['payroll', 'advances', 'contractors'])->update(['is_published' => true]);
    DB::table('legal_parameters')->where('country_code', 'PE')->where('code', 'igv_tasa_pct')->delete();
});

afterEach(fn () => TenancyScope::clear());

function pcGet(string $path): TestResponse
{
    TenancyScope::clear();

    return test()->flushHeaders()->getJson('/api/v1/public'.$path);
}

/**
 * @param  array<string, mixed>  $body
 */
function pcConsole(?string $token, string $method, string $path, array $body = []): TestResponse
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

function pcEnrolled(string $email, string $role): string
{
    PlatformUser::create(['email' => $email, 'full_name' => 'Consola', 'role' => $role, 'status' => 'active', 'password_hash' => Hash::make(PC_PASSWORD)]);
    $login = pcConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => PC_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) pcConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    pcConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

test('WEB-2 · el catálogo público trae productos con su estado, solo planes activos y precios de la consola', function (): void {
    $response = pcGet('/catalog')->assertOk();
    $this->assertMatchesOpenApi($response, 'get', '/public/catalog', 200);

    $products = collect($response->json('data.products'))->keyBy('code');
    expect($products['personas']['status'])->toBe('available')
        ->and($products['tiempo']['status'])->toBe('partial')
        ->and($products['formacion']['status'])->toBe('coming_soon')
        ->and(collect($products['tiempo']['modules'])->firstWhere('code', 'attendance')['published'])->toBeFalse();

    $plans = collect($response->json('data.plans'))->keyBy('code');
    expect($plans->keys()->sort()->values()->all())->toBe(['esencial', 'profesional'])
        ->and($plans['esencial']['price'])->toBe(['amount' => '100.00', 'currency' => 'PEN'])
        ->and($plans['profesional']['price'])->toBeNull()
        ->and(collect($plans['esencial']['limits'])->firstWhere('code', 'employees')['value'])->toBe('50')
        ->and($response->json('data.tax'))->toBe(['code' => 'IGV', 'rate_pct' => null, 'pending_validation' => true]);

    // Nada de organizaciones ni consumo en la respuesta.
    expect(array_keys((array) $response->json('data')))->toBe(['products', 'plans', 'addons', 'modules', 'tax']);

    // Cambiar un precio en la consola se refleja sin desplegar.
    DB::table('plans')->where('code', 'esencial')->update(['price' => '120.00']);
    expect(pcGet('/catalog')->json('data.plans.0.price.amount'))->toBe('120.00');
});

test('WEB-4 · la cotización suma plan y complementos por colaborador con el impuesto vigente y exacto', function (): void {
    DB::table('legal_parameters')->insert(['country_code' => 'PE', 'code' => 'igv_tasa_pct', 'name' => 'IGV (prueba)', 'value_numeric' => '18', 'unit' => 'percent', 'valid_from' => '2020-01-01', 'verified' => true]);

    $response = pcGet('/quote?plan=esencial&employees=7&addons=planilla')->assertOk();
    $this->assertMatchesOpenApi($response, 'get', '/public/quote', 200);
    expect($response->json('data.subtotal'))->toBe(['amount' => '124.50', 'currency' => 'PEN'])
        ->and($response->json('data.tax.amount'))->toBe(['amount' => '22.41', 'currency' => 'PEN'])
        ->and($response->json('data.total'))->toBe(['amount' => '146.91', 'currency' => 'PEN'])
        ->and($response->json('data.lines.1.amount.amount'))->toBe('24.50');
});

test('WEB-4 · sin tasa validada no hay total; un precio sin publicar se muestra «Consultar»', function (): void {
    $quote = pcGet('/quote?plan=esencial&employees=7')->assertOk();
    expect($quote->json('data.subtotal.amount'))->toBe('100.00')
        ->and($quote->json('data.total'))->toBeNull()
        ->and(collect($quote->json('data.notices'))->pluck('code')->all())->toContain('tax_pending');

    $unpriced = pcGet('/quote?plan=profesional&employees=7')->assertOk();
    expect($unpriced->json('data.subtotal'))->toBeNull()
        ->and(collect($unpriced->json('data.notices'))->pluck('code')->all())->toContain('price_on_request');
});

test('RN-WEB-02 · RN-WEB-04 · lo que está en «Próximamente» no se cobra y un requisito se agrega o se avisa', function (): void {
    // Talento no está publicado: queda como aviso y no suma.
    $response = pcGet('/quote?plan=esencial&employees=10&addons=talento')->assertOk();
    expect($response->json('data.waitlist'))->toBe(['talento'])
        ->and($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.subtotal.amount'))->toBe('100.00');

    // Un complemento cuyo módulo exige otro que ningún complemento ofrece: se avisa.
    DB::table('modules')->where('code', 'payroll')->update(['is_published' => true]);
    DB::table('module_dependencies')->updateOrInsert(['module_code' => 'payroll', 'depends_on_code' => 'attendance'], ['is_required' => true]);
    DB::table('addons')->where('code', 'tiempo')->update(['status' => 'active', 'price' => '1.00', 'currency' => 'PEN']);
    $deps = pcGet('/quote?plan=esencial&employees=10&addons=planilla')->assertOk();
    $codes = collect($deps->json('data.notices'))->pluck('code')->all();
    // El complemento Tiempo trae attendance pero no está publicado: va a la lista de espera.
    expect($codes)->toContain('requirement_added')
        ->and($deps->json('data.waitlist'))->toBe(['tiempo']);

    pcGet('/quote?plan=esencial&employees=10&addons=no-existe')->assertStatus(422);
    $invalid = pcGet('/quote?plan=esencial')->assertStatus(422);
    $this->assertMatchesOpenApi($invalid, 'get', '/public/quote', 422);
    pcGet('/quote?plan=empresas&employees=10')->assertNotFound();
});

test('WEB-1 · la API pública no responde desde el host de una organización', function (): void {
    DB::table('tenants')->insertOrIgnore(['id' => PC_TENANT, 'slug' => 'pc-andes', 'name' => 'Andes Catálogo', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    TenancyScope::clear();
    test()->flushHeaders()->withHeaders(['X-Tenant-Id' => PC_TENANT])->getJson('/api/v1/public/catalog')->assertNotFound();
});

test('WEB-2 · la consola publica y retira módulos con auditoría; sin permiso, 403', function (): void {
    $token = pcEnrolled('modulos@gestia.test', 'superadmin');

    $list = pcConsole($token, 'GET', '/modules')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/platform/modules', 200);
    expect(collect($list->json('data'))->firstWhere('code', 'learning')['is_published'])->toBeFalse();

    $published = pcConsole($token, 'POST', '/modules/learning/publish')->assertOk();
    $this->assertMatchesOpenApi($published, 'post', '/platform/modules/{code}/publish', 200);
    expect($published->json('data.is_published'))->toBeTrue()
        ->and(collect(pcGet('/catalog')->json('data.products'))->firstWhere('code', 'formacion')['status'])->toBe('available')
        ->and(DB::table('platform_audit_events')->where('action', 'module.published')->exists())->toBeTrue();

    $retired = pcConsole($token, 'POST', '/modules/learning/unpublish')->assertOk();
    $this->assertMatchesOpenApi($retired, 'post', '/platform/modules/{code}/unpublish', 200);
    expect($retired->json('data.is_published'))->toBeFalse();
    pcConsole($token, 'POST', '/modules/no-existe/publish')->assertNotFound();

    $support = pcEnrolled('soporte-modulos@gestia.test', 'support');
    $denied = pcConsole($support, 'POST', '/modules/learning/publish')->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/platform/modules/{code}/publish', 403);
});
