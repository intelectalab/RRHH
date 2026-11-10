<?php

/**
 * TEN-5 · Organización con varias empresas y selector de empresa.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Shared\Permissions\DataScope;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(DatabaseTransactions::class);

const EMP_TENANT = 'aaaaaaaa-5050-4000-8000-00000000000a';
const EMP_OTHER = 'bbbbbbbb-5050-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => EMP_TENANT, 'slug' => 'emp-org-a', 'name' => 'Grupo A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => EMP_OTHER, 'slug' => 'emp-org-b', 'name' => 'Grupo B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [EMP_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [EMP_OTHER]);

    // Grupo con tres empresas.
    [$this->e1, $this->e2, $this->e3] = TenancyScope::runAs(EMP_TENANT, fn () => [
        LegalEntity::factory()->create(['legal_name' => 'Andina Comercial S.A.C.', 'tax_id' => '20111111111']),
        LegalEntity::factory()->create(['legal_name' => 'Andina Servicios S.A.C.', 'tax_id' => '20222222222']),
        LegalEntity::factory()->create(['legal_name' => 'Andina Logística S.A.C.', 'tax_id' => '20333333333']),
    ]);

    $this->admin = empUser('admin_empresa');

    // Ruta de prueba: un listado laboral que aplica el filtro de empresa en el backend.
    Route::middleware(['api', 'auth:ayni', 'permission:org.legal_entities.view'])->get('/api/v1/__company-probe', function (Request $request) {
        $query = DB::table('legal_entities')->select('id');
        app(DataScope::class)->applyLegalEntity($query, $request, 'org.legal_entities.view', 'id');

        return response()->json(['data' => $query->orderBy('id')->pluck('id')]);
    });
});

afterEach(function (): void {
    TenancyScope::clear();
});

function empUser(string $systemRole, ?string $scope = null, array $legalEntityIds = []): User
{
    return TenancyScope::runAs(EMP_TENANT, function () use ($systemRole, $scope, $legalEntityIds): User {
        $user = User::factory()->create();
        $role = Role::query()->where('system_role_code', $systemRole)->firstOrFail();
        $assignment = $user->roleAssignments()->create(['role_id' => $role->id, 'scope_override' => $scope]);
        foreach ($legalEntityIds as $id) {
            DB::table('user_role_scopes')->insert(['tenant_id' => EMP_TENANT, 'user_role_id' => $assignment->id, 'legal_entity_id' => $id]);
        }

        return $user;
    });
}

function empCall(User $user, string $method, string $path, array $body = [], array $headers = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id] + $headers)
        ->json($method, '/api/v1'.$path, $body);
}

test('un grupo con tres empresas ve los datos consolidados o por empresa según el selector', function (): void {
    $all = empCall($this->admin, 'GET', '/__company-probe');
    expect($all->json('data'))->toHaveCount(3);

    $one = empCall($this->admin, 'GET', '/__company-probe?legal_entity_id='.$this->e2->id);
    expect($one->json('data'))->toBe([$this->e2->id]);

    $filter = empCall($this->admin, 'GET', '/__company-probe?filter[legal_entity_id]='.$this->e3->id);
    expect($filter->json('data'))->toBe([$this->e3->id]);
});

test('un usuario con alcance sobre una sola empresa no ve las demás en el selector', function (): void {
    $hr = empUser('rrhh', 'legal_entity', [$this->e1->id]);

    $list = empCall($hr, 'GET', '/legal-entities');
    $list->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$this->e1->id]);
    $this->assertMatchesOpenApi($list, 'get', '/legal-entities', 200);

    empCall($hr, 'GET', '/legal-entities/'.$this->e2->id)->assertNotFound();
});

test('el filtro de empresa se aplica en el backend: una empresa fuera de alcance responde 404', function (): void {
    $hr = empUser('rrhh', 'legal_entity', [$this->e1->id]);

    expect(empCall($hr, 'GET', '/__company-probe')->json('data'))->toBe([$this->e1->id]);
    empCall($hr, 'GET', '/__company-probe?legal_entity_id='.$this->e2->id)->assertNotFound();
});

test('crear una empresa valida RUC, régimen del país y queda con su régimen vigente', function (): void {
    $response = empCall($this->admin, 'POST', '/legal-entities', [
        'name' => 'Andina Agro S.A.C.', 'tax_id' => '20444444444', 'country' => 'PE', 'currency' => 'PEN',
        'timezone' => 'America/Lima', 'payroll_regime' => 'pe_agrario',
    ]);

    $response->assertCreated()->assertJsonPath('data.payroll_regime', 'pe_agrario')->assertJsonPath('data.status', 'active');
    $this->assertMatchesOpenApi($response, 'post', '/legal-entities', 201);

    TenancyScope::runAs(EMP_TENANT, function () use ($response): void {
        expect(DB::table('audit_events')->where('entity_id', $response->json('data.id'))->where('action', 'legal_entity.created')->count())->toBe(1)
            ->and(DB::table('outbox_events')->where('aggregate_id', $response->json('data.id'))->where('event_type', 'company.created.v1')->count())->toBe(1);
    });
});

test('RUC inválido, régimen inexistente o RUC repetido son rechazados', function (): void {
    $base = ['name' => 'X S.A.C.', 'country' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'payroll_regime' => 'pe_general'];

    $bad = empCall($this->admin, 'POST', '/legal-entities', $base + ['tax_id' => '123']);
    $bad->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($bad, 'post', '/legal-entities', 422);

    empCall($this->admin, 'POST', '/legal-entities', ['payroll_regime' => 'no_existe'] + $base + ['tax_id' => '20555555555'])->assertStatus(422);

    empCall($this->admin, 'POST', '/legal-entities', $base + ['tax_id' => '20111111111'])
        ->assertStatus(409)->assertJsonPath('error.code', 'duplicate');
});

test('cambiar el régimen cierra el vigente e inserta otro con vigencia (regla 2)', function (): void {
    $response = empCall($this->admin, 'PATCH', '/legal-entities/'.$this->e1->id, ['payroll_regime' => 'pe_pequena']);
    $response->assertOk();
    $this->assertMatchesOpenApi($response, 'patch', '/legal-entities/{id}', 200);

    TenancyScope::runAs(EMP_TENANT, function (): void {
        $rows = DB::table('legal_entity_regimes')->where('legal_entity_id', $this->e1->id)->orderBy('valid_from')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->labor_regime_code)->toBe('pe_general')
            ->and($rows[0]->valid_to)->not->toBeNull()
            ->and($rows[1]->labor_regime_code)->toBe('pe_pequena')
            ->and($rows[1]->valid_to)->toBeNull();
    });
});

test('RN-TEN-05: la empresa no se elimina, se archiva y se restaura con acciones', function (): void {
    $archive = empCall($this->admin, 'POST', '/legal-entities/'.$this->e3->id.'/archive', ['reason' => 'Fusión']);
    $archive->assertOk()->assertJsonPath('data.status', 'archived');
    $this->assertMatchesOpenApi($archive, 'post', '/legal-entities/{id}/archive', 200);

    $again = empCall($this->admin, 'POST', '/legal-entities/'.$this->e3->id.'/archive', []);
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/legal-entities/{id}/archive', 409);

    empCall($this->admin, 'PATCH', '/legal-entities/'.$this->e3->id, ['name' => 'No'])->assertStatus(409);

    $restore = empCall($this->admin, 'POST', '/legal-entities/'.$this->e3->id.'/restore', []);
    $restore->assertOk()->assertJsonPath('data.status', 'active');

    expect(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($r) => in_array('DELETE', $r->methods(), true) && str_contains($r->uri(), 'legal-entities')))->toBeFalse();
});

test('RN-TEN-07: con el límite del plan alcanzado no se crea otra empresa', function (): void {
    TenancyScope::runAs(EMP_TENANT, fn () => DB::table('tenant_limits')->insert(['tenant_id' => EMP_TENANT, 'limit_code' => 'legal_entities', 'limit_value' => 3]));

    $response = empCall($this->admin, 'POST', '/legal-entities', [
        'name' => 'Cuarta S.A.C.', 'tax_id' => '20666666666', 'country' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'payroll_regime' => 'pe_general',
    ]);
    $response->assertStatus(403)->assertJsonPath('error.code', 'plan_limit_reached')->assertJsonPath('error.meta.max', 3);
    $this->assertMatchesOpenApi($response, 'post', '/legal-entities', 403);
});

test('sin permiso responde 403 y una empresa de otra organización 404', function (): void {
    $employee = empUser('empleado');
    empCall($employee, 'POST', '/legal-entities', ['name' => 'X'])->assertStatus(403);

    $foreign = TenancyScope::runAs(EMP_OTHER, fn () => LegalEntity::factory()->create(['tax_id' => '20777777777']));
    empCall($this->admin, 'GET', '/legal-entities/'.$foreign->id)->assertNotFound();
    empCall($this->admin, 'GET', '/__company-probe?legal_entity_id='.$foreign->id)->assertOk()->assertJsonPath('data', []);
});

test('el catálogo para registrar empresas viene de la base', function (): void {
    $response = empCall($this->admin, 'GET', '/catalogs/organization');

    $response->assertOk();
    expect(collect($response->json('data.labor_regimes'))->pluck('code'))->toContain('pe_general', 'pe_micro')
        ->and(collect($response->json('data.currencies'))->pluck('code'))->toContain('PEN')
        ->and($response->json('data.tax_id_labels.PE'))->toBe('RUC');
    $this->assertMatchesOpenApi($response, 'get', '/catalogs/organization', 200);
});
