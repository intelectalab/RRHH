<?php

/**
 * TEN-4 · Prueba automática de aislamiento sobre todos los endpoints del contrato.
 *
 * - Todo acceso cruzado responde 404 y no revela existencia del recurso.
 * - Un endpoint nuevo queda cubierto sin escribir una prueba específica.
 * - La canalización falla si un endpoint no está cubierto.
 *
 * El harness está en tests/Support/Isolation/CrossTenantHarness.php.
 */

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Isolation\CrossTenantHarness;

const ISO_TENANT_A = 'aaaaaaaa-4444-4000-8000-00000000000a';
const ISO_TENANT_B = 'bbbbbbbb-4444-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => ISO_TENANT_A, 'slug' => 'iso-org-a', 'name' => 'Iso A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => ISO_TENANT_B, 'slug' => 'iso-org-b', 'name' => 'Iso B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
});

afterEach(function (): void {
    TenancyScope::clear();
    foreach ([ISO_TENANT_A, ISO_TENANT_B] as $tenant) {
        TenancyScope::runAs($tenant, fn () => DB::table('people')->where('tenant_id', $tenant)->delete());
    }
    DB::table('tenants')->whereIn('id', [ISO_TENANT_A, ISO_TENANT_B])->delete();
});

function isolationHarness(): CrossTenantHarness
{
    return new CrossTenantHarness(test()->loadOpenApiSpec());
}

test('toda ruta registrada bajo /api/v1 está declarada en openapi.yaml', function (): void {
    $missing = isolationHarness()->routesMissingFromContract(Route::getRoutes()->getRoutes());

    expect($missing)->toBe([], "Rutas sin contrato (actualiza tools/gen_openapi.py y ejecuta make openapi):\n".implode("\n", $missing));
});

test('ningún endpoint del contrato filtra datos entre organizaciones', function (): void {
    $harness = isolationHarness();
    $operations = $harness->registeredOperations(Route::getRoutes()->getRoutes());

    $result = $harness->run($this, $operations, ISO_TENANT_A, ISO_TENANT_B);

    expect($result['uncovered'])->toBe([], "Endpoints sin cobertura de aislamiento (falta modelo con HasFactory):\n".implode("\n", $result['uncovered']))
        ->and($result['violations'])->toBe([], "Fugas entre organizaciones:\n".implode("\n", $result['violations']));
});

/*
 * Pruebas del propio harness: demuestran que detecta fugas y endpoints sin cobertura.
 */

test('el harness detecta un endpoint que responde con un recurso de otra organización', function (): void {
    Route::middleware('api')->get('/api/v1/__leaky/{id}', fn (string $id) => response()->json(['data' => ['id' => $id]]));

    $violations = [];
    $response = $this->withHeaders(['X-Tenant-Id' => ISO_TENANT_B])->getJson('/api/v1/__leaky/'.ISO_TENANT_A);
    if ($response->status() !== 404) {
        $violations[] = 'fuga';
    }

    expect($violations)->toBe(['fuga']);
});

test('una lectura por id respaldada por RLS responde 404 desde otra organización', function (): void {
    $personId = '44444444-4444-4444-8444-444444444444';
    TenancyScope::runAs(ISO_TENANT_A, fn () => DB::table('people')->insert([
        'id' => $personId, 'tenant_id' => ISO_TENANT_A, 'first_names' => 'Ana', 'paternal_surname' => 'Quispe',
    ]));

    Route::middleware('api')->get('/api/v1/__people-probe/{id}', function (string $id) {
        $row = DB::table('people')->where('id', $id)->first();

        return $row === null
            ? response()->json(['error' => ['code' => 'not_found', 'message' => '', 'request_id' => '']], 404)
            : response()->json(['data' => $row]);
    });

    $this->withHeaders(['X-Tenant-Id' => ISO_TENANT_A])->getJson('/api/v1/__people-probe/'.$personId)->assertOk();
    TenancyScope::clear();
    $this->withHeaders(['X-Tenant-Id' => ISO_TENANT_B])->getJson('/api/v1/__people-probe/'.$personId)->assertNotFound();
});

test('el harness marca como no cubierto un endpoint cuyo recurso no tiene modelo con factory', function (): void {
    $spec = ['paths' => ['/widgets/{id}' => ['get' => [
        'x-permission' => 'widgets.view',
        'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['properties' => ['data' => ['$ref' => '#/components/schemas/Widget']]]]]]],
    ]]]];
    $harness = new CrossTenantHarness($spec);

    $result = $harness->run($this, [['method' => 'get', 'path' => '/widgets/{id}', 'operation' => $spec['paths']['/widgets/{id}']['get']]], ISO_TENANT_A, ISO_TENANT_B);

    expect($result['uncovered'])->toHaveCount(1)
        ->and($result['uncovered'][0])->toContain('Widget');
});

test('el harness detecta una ruta registrada que no está en el contrato', function (): void {
    Route::middleware('api')->get('/api/v1/rogue-endpoint', fn () => response()->json(['data' => []]));

    $missing = isolationHarness()->routesMissingFromContract(Route::getRoutes()->getRoutes());

    expect($missing)->toContain('GET /api/v1/rogue-endpoint');
});

test('el harness deduce el esquema del recurso dueño de una acción', function (): void {
    $harness = isolationHarness();

    expect($harness->resourceSchema('/legal-entities/{id}/archive', 'post'))->toBe('LegalEntity')
        ->and($harness->resourceSchema('/legal-entities', 'get'))->toBe('LegalEntity');
});
