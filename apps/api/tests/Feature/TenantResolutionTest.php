<?php

/**
 * TEN-1 · Resolución de la organización por host (docs/02-arquitectura.md §5.1).
 */

use App\Shared\Http\Middleware\ResolveTenantContext;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

const RES_TENANT_A = 'aaaaaaaa-1111-4000-8000-000000000001';
const RES_TENANT_B = 'bbbbbbbb-1111-4000-8000-000000000002';
const RES_TENANT_SUSP = 'cccccccc-1111-4000-8000-000000000003';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    config()->set('tenancy.base_domain', 'ayni.test');

    DB::table('tenants')->insertOrIgnore([
        ['id' => RES_TENANT_A, 'slug' => 'res-org-a', 'name' => 'Org A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => RES_TENANT_B, 'slug' => 'res-org-b', 'name' => 'Org B', 'status' => 'trial', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => RES_TENANT_SUSP, 'slug' => 'res-org-s', 'name' => 'Org S', 'status' => 'suspended', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::table('tenant_domains')->insertOrIgnore([
        ['tenant_id' => RES_TENANT_A, 'domain' => 'rrhh.cliente-a.pe', 'kind' => 'custom', 'verified_at' => now()],
        ['tenant_id' => RES_TENANT_B, 'domain' => 'rrhh.cliente-b.pe', 'kind' => 'custom', 'verified_at' => null],
    ]);

    Route::middleware(['api'])->get('/api/v1/__tenant-probe', fn () => response()->json([
        'data' => ['tenant' => TenancyScope::current()],
    ]));
});

afterEach(function (): void {
    TenancyScope::clear();
    DB::table('tenant_domains')->whereIn('tenant_id', [RES_TENANT_A, RES_TENANT_B])->delete();
    DB::table('tenants')->whereIn('id', [RES_TENANT_A, RES_TENANT_B, RES_TENANT_SUSP])->delete();
    app()['env'] = 'testing';
});

test('resuelve la organización por subdominio', function (): void {
    $this->getJson('http://res-org-a.ayni.test/api/v1/__tenant-probe')
        ->assertOk()
        ->assertJsonPath('data.tenant', RES_TENANT_A);
});

test('resuelve la organización por dominio propio verificado', function (): void {
    $this->getJson('http://rrhh.cliente-a.pe/api/v1/__tenant-probe')
        ->assertOk()
        ->assertJsonPath('data.tenant', RES_TENANT_A);
});

test('un dominio propio sin verificar no resuelve organización', function (): void {
    $this->getJson('http://rrhh.cliente-b.pe/api/v1/__tenant-probe')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'missing_tenant_context');
});

test('un subdominio inexistente, reservado o anidado no resuelve organización', function (string $host): void {
    $this->getJson("http://{$host}/api/v1/__tenant-probe")
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'missing_tenant_context');
})->with(['no-existe.ayni.test', 'admin.ayni.test', 'x.res-org-a.ayni.test', 'ayni.test']);

test('una organización suspendida responde 403 tenant_suspended', function (): void {
    $this->getJson('http://res-org-s.ayni.test/api/v1/__tenant-probe')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'tenant_suspended');
});

test('una credencial de otra organización responde 404 sin revelar existencia', function (): void {
    $this->actingAs(new GenericUser(['id' => 'u-1', 'tenant_id' => RES_TENANT_B]))
        ->getJson('http://res-org-a.ayni.test/api/v1/__tenant-probe')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

test('una credencial de la misma organización pasa', function (): void {
    $this->actingAs(new GenericUser(['id' => 'u-1', 'tenant_id' => RES_TENANT_A]))
        ->getJson('http://res-org-a.ayni.test/api/v1/__tenant-probe')
        ->assertOk()
        ->assertJsonPath('data.tenant', RES_TENANT_A);
});

test('el host manda sobre la cabecera X-Tenant-Id', function (): void {
    $this->withHeader('X-Tenant-Id', RES_TENANT_B)
        ->getJson('http://res-org-a.ayni.test/api/v1/__tenant-probe')
        ->assertOk()
        ->assertJsonPath('data.tenant', RES_TENANT_A);
});

test('la cabecera X-Tenant-Id funciona en pruebas', function (): void {
    $this->withHeader('X-Tenant-Id', RES_TENANT_B)
        ->getJson('http://ayni.test/api/v1/__tenant-probe')
        ->assertOk()
        ->assertJsonPath('data.tenant', RES_TENANT_B);
});

test('la cabecera X-Tenant-Id y el parámetro tenant_id se ignoran fuera de pruebas', function (): void {
    app()['env'] = 'production';

    $this->withHeader('X-Tenant-Id', RES_TENANT_A)
        ->getJson('http://ayni.test/api/v1/__tenant-probe?tenant_id='.RES_TENANT_A)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'missing_tenant_context');
});

test('fuera de pruebas no se reutiliza un contexto previo de otra petición', function (): void {
    TenancyScope::set(RES_TENANT_B);
    app()['env'] = 'production';

    $this->getJson('http://ayni.test/api/v1/__tenant-probe')
        ->assertStatus(400);
});

test('el middleware de resolución está registrado en el grupo api', function (): void {
    $api = app(Kernel::class)->getMiddlewareGroups()['api'] ?? [];

    expect($api)->toContain(ResolveTenantContext::class);
});
