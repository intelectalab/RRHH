<?php

use App\Shared\Database\DatabaseConventionsChecker;
use App\Shared\Http\Middleware\ResolveTenantContext;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Tenancy\TenantAware;
use App\Shared\Tenancy\TenantContextRequiredException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

const TEST_TENANT_A = 'aaaaaaaa-0000-4000-8000-000000000001';
const TEST_TENANT_B = 'bbbbbbbb-0000-4000-8000-000000000002';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Tenancy isolation tests require PostgreSQL.');
    }

    TenancyScope::clear();

    // Ensure tenants exist in DB
    DB::statement("
        INSERT INTO tenants (id, slug, name, country_code, default_currency)
        VALUES
            ('".TEST_TENANT_A."', 'org-a-test', 'Org A Test', 'PE', 'PEN'),
            ('".TEST_TENANT_B."', 'org-b-test', 'Org B Test', 'PE', 'PEN')
        ON CONFLICT (id) DO NOTHING;
    ");
});

afterEach(function (): void {
    if (DB::getDriverName() === 'pgsql') {
        TenancyScope::clear();

        // Clean up test records created during tests
        DB::statement("DELETE FROM people WHERE tenant_id IN ('".TEST_TENANT_A."', '".TEST_TENANT_B."');");
        DB::statement("DELETE FROM tenants WHERE id IN ('".TEST_TENANT_A."', '".TEST_TENANT_B."');");
    }
});

test('ayni_app role has NO BYPASSRLS and is NOT superuser', function (): void {
    $role = DB::selectOne("
        SELECT rolname, rolsuper, rolbypassrls
        FROM pg_roles
        WHERE rolname = 'ayni_app'
    ");

    expect($role)->not->toBeNull()
        ->and($role->rolname)->toBe('ayni_app')
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

test('tenancy scope applies tenant to database session and switches to ayni_app', function (): void {
    TenancyScope::set(TEST_TENANT_A);

    expect(TenancyScope::current())->toBe(TEST_TENANT_A);

    $currentSetting = DB::scalar("SELECT current_setting('app.tenant_id', true)");
    expect($currentSetting)->toBe(TEST_TENANT_A);

    $currentUser = DB::scalar('SELECT current_user');
    expect($currentUser)->toBe('ayni_app');

    TenancyScope::clear();

    expect(TenancyScope::current())->toBeNull();

    $resetUser = DB::scalar('SELECT current_user');
    expect($resetUser)->not->toBe('ayni_app');
});

test('tenant B cannot read, list, modify or delete data belonging to tenant A', function (): void {
    $personAId = '11111111-1111-4111-8111-111111111111';

    // 1. Inserción con contexto de Organización A
    TenancyScope::set(TEST_TENANT_A);

    DB::table('people')->insert([
        'id' => $personAId,
        'tenant_id' => TEST_TENANT_A,
        'first_names' => 'Carlos',
        'paternal_surname' => 'Mendoza',
    ]);

    $countA = DB::table('people')->count();
    expect($countA)->toBe(1);

    $foundInA = DB::table('people')->where('id', $personAId)->first();
    expect($foundInA)->not->toBeNull()
        ->and($foundInA->first_names)->toBe('Carlos');

    // 2. Cambio de contexto a Organización B
    TenancyScope::set(TEST_TENANT_B);

    // Listado: 0 registros visibles
    $countB = DB::table('people')->count();
    expect($countB)->toBe(0);

    // Búsqueda por ID: 0 registros (null)
    $foundInB = DB::table('people')->where('id', $personAId)->first();
    expect($foundInB)->toBeNull();

    // Modificación de fila de A desde B: 0 filas afectadas
    $affectedUpdates = DB::table('people')
        ->where('id', $personAId)
        ->update(['first_names' => 'Atacante']);
    expect($affectedUpdates)->toBe(0);

    // Borrado de fila de A desde B: 0 filas afectadas
    $affectedDeletes = DB::table('people')
        ->where('id', $personAId)
        ->delete();
    expect($affectedDeletes)->toBe(0);

    // 3. Verificación de que la fila de A permanece inalterada
    TenancyScope::set(TEST_TENANT_A);
    $finalA = DB::table('people')->where('id', $personAId)->first();
    expect($finalA)->not->toBeNull()
        ->and($finalA->first_names)->toBe('Carlos');
});

test('inserting a row with a different tenant_id is rejected by PostgreSQL RLS policy (42501)', function (): void {
    TenancyScope::set(TEST_TENANT_B);

    // Con sesión de B, intentar insertar una fila con tenant_id de A debe violar RLS
    $thrown = false;
    try {
        DB::table('people')->insert([
            'id' => '22222222-2222-4222-8222-222222222222',
            'tenant_id' => TEST_TENANT_A, // diferente al contexto activo TEST_TENANT_B
            'first_names' => 'Intruso',
            'paternal_surname' => 'Ilegal',
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        // SQLSTATE 42501 = insufficient_privilege / row level security violation
        expect($e->getCode())->toBe('42501');
    }

    expect($thrown)->toBeTrue();
});

test('composite foreign key prevents cross-tenant reference even across business tables (23503)', function (): void {
    $personAId = '11111111-1111-4111-8111-111111111111';

    // Crear persona en Organización A
    TenancyScope::set(TEST_TENANT_A);
    DB::table('people')->insert([
        'id' => $personAId,
        'tenant_id' => TEST_TENANT_A,
        'first_names' => 'Ana',
        'paternal_surname' => 'Torres',
    ]);

    // Cambiar a Organización B
    TenancyScope::set(TEST_TENANT_B);

    // Intentar que un registro de B (emergency_contacts) apunte a la persona de A
    $thrown = false;
    try {
        DB::table('emergency_contacts')->insert([
            'id' => '33333333-3333-4333-8333-333333333333',
            'tenant_id' => TEST_TENANT_B,
            'person_id' => $personAId, // Persona pertenece a Tenant A
            'full_name' => 'Contacto Cruzado',
            'phone_enc' => 'enc_987654321',
            'sort_order' => 1,
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        // SQLSTATE 23503 = foreign_key_violation
        expect($e->getCode())->toBe('23503');
    }

    expect($thrown)->toBeTrue();
});

test('database conventions checker detects rogue table without RLS', function (): void {
    $checker = new DatabaseConventionsChecker;

    DB::statement('CREATE TABLE rogue_no_rls_table (id serial PRIMARY KEY, tenant_id uuid NOT NULL, data text);');

    try {
        $violations = $checker->checkMissingOrUnforcedRls();
        expect($violations)->toContain('rogue_no_rls_table');
    } finally {
        DB::statement('DROP TABLE IF EXISTS rogue_no_rls_table;');
    }
});

test('ResolveTenantContext middleware sets TenancyScope from X-Tenant-Id header', function (): void {
    Route::middleware(['api', ResolveTenantContext::class])->get('/api/v1/test-tenant-context', function () {
        return response()->json([
            'data' => [
                'current_tenant' => TenancyScope::current(),
            ],
        ]);
    });

    $response = $this->withHeader('X-Tenant-Id', TEST_TENANT_A)
        ->getJson('/api/v1/test-tenant-context');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'current_tenant' => TEST_TENANT_A,
            ],
        ]);
});

test('ResolveTenantContext middleware rejects missing tenant on protected endpoints with 400', function (): void {
    Route::middleware(['api', ResolveTenantContext::class])->get('/api/v1/protected-resource', function () {
        return response()->json(['data' => ['ok' => true]]);
    });

    $response = $this->getJson('/api/v1/protected-resource');

    $response->assertStatus(400)
        ->assertJson([
            'error' => [
                'code' => 'missing_tenant_context',
            ],
        ]);
});

test('health endpoint is accessible without tenant context', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'status',
                'timestamp',
            ],
        ]);
});

test('TenancyScope runAs switches context and automatically restores previous', function (): void {
    TenancyScope::set(TEST_TENANT_A);
    expect(TenancyScope::current())->toBe(TEST_TENANT_A);

    $result = TenancyScope::runAs(TEST_TENANT_B, function () {
        expect(TenancyScope::current())->toBe(TEST_TENANT_B);

        return 'done_in_b';
    });

    expect($result)->toBe('done_in_b');
    expect(TenancyScope::current())->toBe(TEST_TENANT_A);

    TenancyScope::clear();
    expect(TenancyScope::current())->toBeNull();
});

test('TenantAware trait captures and restores tenant in queue jobs', function (): void {
    $dummyJob = new class
    {
        use TenantAware;

        public function __construct()
        {
            $this->initializeTenantAware();
        }
    };

    TenancyScope::set(TEST_TENANT_A);
    $job = new $dummyJob;

    expect($job->tenantId)->toBe(TEST_TENANT_A);

    // Limpiamos contexto para simular hilo de cola diferente
    TenancyScope::clear();
    expect(TenancyScope::current())->toBeNull();

    // Restauramos contexto en el worker
    $job->restoreTenantContext();
    expect(TenancyScope::current())->toBe(TEST_TENANT_A);
});

test('TenancyScope requireTenant throws exception when context is missing', function (): void {
    TenancyScope::clear();

    expect(fn () => TenancyScope::requireTenant())
        ->toThrow(TenantContextRequiredException::class);
});
