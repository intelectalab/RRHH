<?php

/**
 * ORG-2 · Sedes, sucursales y ubicaciones (geocerca, zona horaria, feriados, centro de riesgo; archivada
 * conserva su historia y no admite nuevas asignaciones; RN-ORG-05).
 * ORG-4 · Centros de costo con código, jerarquía y vigencia; distribución de un colaborador entre varios
 * centros que suma siempre 100 % (RN-ORG-06); cerrado no admite nuevas asignaciones.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Database\Factories\EmploymentCostDistributionFactory;
use App\Modules\Organization\Domain\CostDistribution;
use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const SCC_TENANT = 'aaaaaaaa-0a62-4000-8000-00000000000a';
const SCC_OTHER = 'bbbbbbbb-0a62-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([SCC_TENANT => 'scc-org', SCC_OTHER => 'scc-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = sccUser(SCC_TENANT, 'admin_empresa');
    $this->lima = TenancyScope::runAs(SCC_TENANT, fn (): string => LegalEntity::factory()->create(['legal_name' => 'Lima SAC'])->id);
    $this->sur = TenancyScope::runAs(SCC_TENANT, fn (): string => LegalEntity::factory()->create(['legal_name' => 'Sur SAC', 'timezone' => 'America/Bogota'])->id);
});

afterEach(fn () => TenancyScope::clear());

/**
 * @param  array<int, string>  $legalEntityIds
 */
function sccUser(string $tenant, string $role, ?string $scope = null, array $legalEntityIds = []): User
{
    return TenancyScope::runAs($tenant, function () use ($tenant, $role, $scope, $legalEntityIds): User {
        $u = User::factory()->create();
        $assignment = $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id'), 'scope_override' => $scope]);
        foreach ($legalEntityIds as $id) {
            DB::table('user_role_scopes')->insert(['tenant_id' => $tenant, 'user_role_id' => $assignment->id, 'legal_entity_id' => $id]);
        }

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function sccCall(User $user, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()] + $headers)
        ->json($method, '/api/v1'.$path, $body);
}

/** Relación laboral activa asignada hoy a una sede (y opcionalmente a un centro de costo). */
function sccEmployment(string $legalEntityId, string $siteId, ?string $costCenterId = null): string
{
    return TenancyScope::runAs(SCC_TENANT, function () use ($legalEntityId, $siteId, $costCenterId): string {
        $employment = EmploymentCostDistributionFactory::employment($legalEntityId);
        $unit = (string) Str::uuid();
        $profile = (string) Str::uuid();
        DB::table('org_units')->insert(['id' => $unit, 'tenant_id' => SCC_TENANT, 'code' => 'U'.Str::random(6), 'name' => 'Unidad', 'kind' => 'area']);
        DB::table('job_profiles')->insert(['id' => $profile, 'tenant_id' => SCC_TENANT, 'code' => 'P'.Str::random(6), 'name' => 'Puesto']);
        DB::table('employment_assignments')->insert([
            'tenant_id' => SCC_TENANT, 'employment_id' => $employment, 'job_profile_id' => $profile, 'org_unit_id' => $unit,
            'site_id' => $siteId, 'cost_center_id' => $costCenterId, 'valid_from' => '2025-01-01',
        ]);

        return $employment;
    });
}

/**
 * @return list<string>
 */
function sccFields(TestResponse $r): array
{
    return collect($r->json('error.details'))->pluck('field')->map(fn ($f) => (string) $f)->values()->all();
}

function sccCalendar(): string
{
    return TenancyScope::runAs(SCC_TENANT, function (): string {
        $id = (string) Str::uuid();
        DB::table('holiday_calendars')->insert(['id' => $id, 'tenant_id' => SCC_TENANT, 'code' => 'CAL'.Str::random(5), 'name' => 'Feriados sede', 'country_code' => 'PE']);

        return $id;
    });
}

// ---------------------------------------------------------------- ORG-2 · Sedes

test('una sede guarda dirección, ubigeo, coordenadas y radio de la geocerca, zona horaria, feriados y centro de riesgo', function (): void {
    $calendar = sccCalendar();
    $r = sccCall($this->admin, 'POST', '/sites', [
        'legal_entity_id' => $this->sur, 'code' => 'are-01', 'name' => 'Planta Arequipa', 'kind' => 'plant',
        'address' => 'Av. Industrial 123', 'district_code' => '040101', 'latitude' => -16.398803, 'longitude' => -71.536883,
        'geofence_radius_m' => 150, 'holiday_calendar_id' => $calendar, 'establishment_code' => '0002', 'risk_site' => true,
    ]);
    $r->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/sites', 201);
    expect($r->json('data'))->toMatchArray([
        'code' => 'ARE-01', 'kind' => 'plant', 'country' => 'PE', 'latitude' => -16.398803, 'longitude' => -71.536883,
        'geofence_radius_m' => 150, 'holiday_calendar_id' => $calendar, 'risk_site' => true, 'status' => 'active',
        'timezone' => null, 'effective_timezone' => 'America/Bogota', 'allowed_actions' => ['archive'],
    ]);
    $id = $r->json('data.id');

    $show = sccCall($this->admin, 'GET', '/sites/'.$id);
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/sites/{id}', 200);

    $list = sccCall($this->admin, 'GET', '/sites?filter[risk_site]=true');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/sites', 200);
    expect(collect($list->json('data'))->pluck('id'))->toContain($id);

    $patch = sccCall($this->admin, 'PATCH', '/sites/'.$id, ['timezone' => 'America/Lima', 'geofence_radius_m' => 200], ['If-Match' => '"1"']);
    $patch->assertOk();
    $this->assertMatchesOpenApi($patch, 'patch', '/sites/{id}', 200);
    expect($patch->json('data.effective_timezone'))->toBe('America/Lima')->and($patch->json('data.geofence_radius_m'))->toBe(200);

    $audit = TenancyScope::runAs(SCC_TENANT, fn () => DB::table('audit_events')->where('entity_id', $id)->orderBy('occurred_at')->pluck('action')->all());
    expect($audit)->toContain('site.created')->toContain('site.updated');
});

test('datos inválidos de la sede responden 422 (geocerca sin coordenadas, latitud fuera de rango, código repetido)', function (): void {
    $bad = sccCall($this->admin, 'POST', '/sites', ['code' => 'X1', 'name' => 'Sin coordenadas', 'geofence_radius_m' => 100]);
    $bad->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect(sccFields($bad))->toContain('geofence_radius_m');
    $this->assertMatchesOpenApi($bad, 'post', '/sites', 422);

    sccCall($this->admin, 'POST', '/sites', ['code' => 'X2', 'name' => 'Polo', 'latitude' => 95, 'longitude' => 10])->assertStatus(422);
    sccCall($this->admin, 'POST', '/sites', ['code' => 'X3', 'name' => 'Solo latitud', 'latitude' => -12])->assertStatus(422);
    sccCall($this->admin, 'POST', '/sites', ['code' => 'X4', 'name' => 'Zona', 'timezone' => 'Lima/Peru'])->assertStatus(422);
    sccCall($this->admin, 'POST', '/sites', ['code' => 'DUP', 'name' => 'Primera'])->assertCreated();
    sccCall($this->admin, 'POST', '/sites', ['code' => 'dup', 'name' => 'Segunda'])->assertStatus(422);
});

test('una sede con colaboradores activos no se archiva (RN-ORG-05); archivada conserva su historia y no admite nuevas asignaciones', function (): void {
    $site = sccCall($this->admin, 'POST', '/sites', ['legal_entity_id' => $this->lima, 'code' => 'LIM', 'name' => 'Sede Lima'])->json('data.id');
    $employment = sccEmployment($this->lima, $site);

    $blocked = sccCall($this->admin, 'POST', "/sites/{$site}/archive", []);
    $blocked->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-ORG-05');
    $this->assertMatchesOpenApi($blocked, 'post', '/sites/{id}/archive', 422);

    // La persona cesa: ya no hay relaciones activas en la sede.
    TenancyScope::runAs(SCC_TENANT, fn () => DB::table('employments')->where('id', $employment)->update(['status' => 'terminated']));
    $archived = sccCall($this->admin, 'POST', "/sites/{$site}/archive", ['reason' => 'Cierre del local']);
    $archived->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.allowed_actions', ['restore']);
    $this->assertMatchesOpenApi($archived, 'post', '/sites/{id}/archive', 200);

    // Historia intacta y sigue legible.
    TenancyScope::runAs(SCC_TENANT, function () use ($employment, $site): void {
        expect(DB::table('employment_assignments')->where('employment_id', $employment)->value('site_id'))->toBe($site);
        expect(app(StructureAvailability::class)->siteAcceptsAssignments($site))->toBeFalse();
    });
    sccCall($this->admin, 'GET', '/sites/'.$site)->assertOk()->assertJsonPath('data.code', 'LIM');

    // Transición inválida y edición de una archivada: 409.
    $again = sccCall($this->admin, 'POST', "/sites/{$site}/archive", []);
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/sites/{id}/archive', 409);
    sccCall($this->admin, 'PATCH', '/sites/'.$site, ['name' => 'Otro'])->assertStatus(409);

    $restored = sccCall($this->admin, 'POST', "/sites/{$site}/restore", []);
    $restored->assertOk()->assertJsonPath('data.status', 'active');
    TenancyScope::runAs(SCC_TENANT, fn () => expect(app(StructureAvailability::class)->siteAcceptsAssignments($site, $this->lima))->toBeTrue()
        ->and(app(StructureAvailability::class)->siteAcceptsAssignments($site, $this->sur))->toBeFalse());
});

test('el estado de la sede no se cambia por PATCH', function (): void {
    $site = sccCall($this->admin, 'POST', '/sites', ['code' => 'PAT', 'name' => 'Parche'])->json('data.id');
    sccCall($this->admin, 'PATCH', '/sites/'.$site, ['status' => 'archived', 'name' => 'Parche 2'])->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.name', 'Parche 2');
});

test('el alcance por empresa se aplica en la consulta y fuera de él responde 404', function (): void {
    $limaSite = sccCall($this->admin, 'POST', '/sites', ['legal_entity_id' => $this->lima, 'code' => 'L1', 'name' => 'Lima 1'])->json('data.id');
    $surSite = sccCall($this->admin, 'POST', '/sites', ['legal_entity_id' => $this->sur, 'code' => 'S1', 'name' => 'Sur 1'])->json('data.id');
    $hr = sccUser(SCC_TENANT, 'rrhh', 'legal_entity', [$this->lima]);

    $ids = collect(sccCall($hr, 'GET', '/sites')->json('data'))->pluck('id');
    expect($ids)->toContain($limaSite)->not->toContain($surSite);
    sccCall($hr, 'GET', '/sites/'.$surSite)->assertNotFound();
    sccCall($hr, 'POST', '/sites', ['legal_entity_id' => $this->sur, 'code' => 'S2', 'name' => 'Ajena'])->assertStatus(422);

    $intruder = sccUser(SCC_OTHER, 'admin_empresa');
    sccCall($intruder, 'GET', '/sites/'.$limaSite)->assertNotFound();
    expect(collect(sccCall($intruder, 'GET', '/sites')->json('data'))->pluck('id'))->not->toContain($limaSite);
});

test('sin permiso responde 403 y con el módulo apagado 403 module_not_enabled', function (): void {
    $employee = sccUser(SCC_TENANT, 'empleado');
    $denied = sccCall($employee, 'POST', '/sites', ['code' => 'NO', 'name' => 'No']);
    $denied->assertStatus(403);
    $this->assertMatchesOpenApi($denied, 'post', '/sites', 403);
    sccCall($employee, 'POST', '/cost-centers', ['code' => 'NO', 'name' => 'No'])->assertStatus(403);

    IdentityFixtures::disableModule(SCC_TENANT, 'core');
    sccCall($this->admin, 'GET', '/sites')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
    sccCall($this->admin, 'GET', '/cost-centers')->assertStatus(403)->assertJsonPath('error.code', 'module_not_enabled');
});

// ---------------------------------------------------------------- ORG-4 · Centros de costo

test('centros de costo con código, jerarquía y vigencia; el árbol no admite ciclos', function (): void {
    $root = sccCall($this->admin, 'POST', '/cost-centers', ['legal_entity_id' => $this->lima, 'code' => 'adm', 'name' => 'Administración', 'accounting_code' => '9411', 'opened_on' => '2026-01-01']);
    $root->assertCreated();
    $this->assertMatchesOpenApi($root, 'post', '/cost-centers', 201);
    $rootId = $root->json('data.id');
    $child = sccCall($this->admin, 'POST', '/cost-centers', ['legal_entity_id' => $this->lima, 'parent_id' => $rootId, 'code' => 'ADM-TI', 'name' => 'Tecnología']);
    $child->assertCreated()->assertJsonPath('data.path', ['ADM', 'ADM-TI']);
    $childId = $child->json('data.id');

    // Ciclo: la raíz no puede colgar de su propio hijo.
    $cycle = sccCall($this->admin, 'PATCH', '/cost-centers/'.$rootId, ['parent_id' => $childId]);
    $cycle->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect(sccFields($cycle))->toContain('parent_id');
    $this->assertMatchesOpenApi($cycle, 'patch', '/cost-centers/{id}', 422);
    // Padre de otra empresa.
    sccCall($this->admin, 'POST', '/cost-centers', ['legal_entity_id' => $this->sur, 'parent_id' => $rootId, 'code' => 'X', 'name' => 'Cruzado'])->assertStatus(422);
    // Código repetido.
    sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'ADM', 'name' => 'Repetido'])->assertStatus(422);

    $list = sccCall($this->admin, 'GET', '/cost-centers?filter[parent_id]='.$rootId);
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/cost-centers', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$childId]);
    $show = sccCall($this->admin, 'GET', '/cost-centers/'.$rootId);
    $this->assertMatchesOpenApi($show, 'get', '/cost-centers/{id}', 200);
    expect($show->json('data'))->toMatchArray(['opened_on' => '2026-01-01', 'closed_on' => null, 'status' => 'active', 'accounting_code' => '9411']);
});

test('un centro de costo cerrado no admite nuevas asignaciones; se cierra con fecha y se reabre por acción', function (): void {
    $root = sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'OPS', 'name' => 'Operaciones'])->json('data.id');
    $child = sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'OPS-1', 'name' => 'Turno 1', 'parent_id' => $root])->json('data.id');

    // Con un subcentro abierto no se cierra.
    sccCall($this->admin, 'POST', "/cost-centers/{$root}/close", ['closed_on' => '2026-12-31'])->assertStatus(422);

    $closed = sccCall($this->admin, 'POST', "/cost-centers/{$child}/close", ['closed_on' => '2026-06-30', 'reason' => 'Fin del turno']);
    $closed->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed_on', '2026-06-30');
    $this->assertMatchesOpenApi($closed, 'post', '/cost-centers/{id}/close', 200);
    TenancyScope::runAs(SCC_TENANT, fn () => expect(app(StructureAvailability::class)->costCenterAcceptsAssignments($child, '2026-05-01'))->toBeFalse());

    // Cerrar dos veces o reabrir uno activo: 409.
    $twice = sccCall($this->admin, 'POST', "/cost-centers/{$child}/close", []);
    $twice->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($twice, 'post', '/cost-centers/{id}/close', 409);
    $reopenActive = sccCall($this->admin, 'POST', "/cost-centers/{$root}/reopen", []);
    $reopenActive->assertStatus(409);
    $this->assertMatchesOpenApi($reopenActive, 'post', '/cost-centers/{id}/reopen', 409);

    $reopened = sccCall($this->admin, 'POST', "/cost-centers/{$child}/reopen", []);
    $reopened->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.closed_on', null);
    $this->assertMatchesOpenApi($reopened, 'post', '/cost-centers/{id}/reopen', 200);

    $archived = sccCall($this->admin, 'POST', "/cost-centers/{$child}/archive", []);
    $archived->assertOk()->assertJsonPath('data.status', 'archived');
    TenancyScope::runAs(SCC_TENANT, fn () => expect(app(StructureAvailability::class)->costCenterAcceptsAssignments($child, '2026-05-01'))->toBeFalse());
    sccCall($this->admin, 'POST', "/cost-centers/{$child}/restore", [])->assertOk()->assertJsonPath('data.status', 'active');

    $audit = TenancyScope::runAs(SCC_TENANT, fn () => DB::table('audit_events')->where('entity_id', $child)->pluck('action')->all());
    expect($audit)->toContain('cost_center.closed')->toContain('cost_center.reopened')->toContain('cost_center.archived')->toContain('cost_center.restored');
});

// ---------------------------------------------------------------- ORG-4 · Distribución (RN-ORG-06)

test('la distribución de un colaborador suma siempre 100 % y cambia con vigencia', function (): void {
    $site = sccCall($this->admin, 'POST', '/sites', ['legal_entity_id' => $this->lima, 'code' => 'D1', 'name' => 'Sede D'])->json('data.id');
    $employment = sccEmployment($this->lima, $site);
    $a = sccCall($this->admin, 'POST', '/cost-centers', ['legal_entity_id' => $this->lima, 'code' => 'VTA', 'name' => 'Ventas'])->json('data.id');
    $b = sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'MKT', 'name' => 'Marketing (compartido)'])->json('data.id');
    $c = sccCall($this->admin, 'POST', '/cost-centers', ['legal_entity_id' => $this->lima, 'code' => 'LOG', 'name' => 'Logística'])->json('data.id');
    $path = "/employments/{$employment}/cost-distributions";

    $first = sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-01-01', 'lines' => [
        ['cost_center_id' => $a, 'percentage' => '60'], ['cost_center_id' => $b, 'percentage' => 40],
    ]]);
    $first->assertCreated();
    $this->assertMatchesOpenApi($first, 'post', '/employments/{employment_id}/cost-distributions', 201);
    expect(collect($first->json('data.lines'))->pluck('percentage', 'code')->all())->toBe(['VTA' => '60.00', 'MKT' => '40.00']);

    // No suma 100, se repite un centro o un porcentaje es cero: 422 sin escribir nada.
    $bad = sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-07-01', 'lines' => [
        ['cost_center_id' => $a, 'percentage' => '33.33'], ['cost_center_id' => $c, 'percentage' => '33.33'],
    ]]);
    $bad->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    expect(sccFields($bad))->toContain('lines');
    $this->assertMatchesOpenApi($bad, 'post', '/employments/{employment_id}/cost-distributions', 422);
    sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-07-01', 'lines' => [
        ['cost_center_id' => $a, 'percentage' => '50'], ['cost_center_id' => $a, 'percentage' => '50'],
    ]])->assertStatus(422);
    sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-07-01', 'lines' => [
        ['cost_center_id' => $a, 'percentage' => '100.001'],
    ]])->assertStatus(422);

    // Cambio con fecha: cierra la vigente el día anterior.
    $second = sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-07-01', 'reason' => 'Reorganización', 'lines' => [
        ['cost_center_id' => $a, 'percentage' => '33.34'], ['cost_center_id' => $b, 'percentage' => '33.33'], ['cost_center_id' => $c, 'percentage' => '33.33'],
    ]]);
    $second->assertCreated();

    $history = sccCall($this->admin, 'GET', $path);
    $history->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/employments/{employment_id}/cost-distributions', 200);
    expect(collect($history->json('data'))->map(fn ($d) => [$d['valid_from'], $d['valid_to']])->all())
        ->toBe([['2026-07-01', null], ['2026-01-01', '2026-06-30']]);
    expect(collect(sccCall($this->admin, 'GET', $path.'?as_of=2026-03-15')->json('data.0.lines'))->pluck('code')->all())->toBe(['VTA', 'MKT']);
    expect(count(sccCall($this->admin, 'GET', $path.'?as_of=2026-08-01')->json('data.0.lines')))->toBe(3);
    sccCall($this->admin, 'GET', $path.'?as_of=ayer')->assertStatus(400);

    // Toda distribución guardada suma exactamente 100.
    TenancyScope::runAs(SCC_TENANT, function () use ($employment): void {
        $totals = DB::table('employment_cost_distribution_lines as l')->join('employment_cost_distributions as d', 'd.id', '=', 'l.employment_cost_distribution_id')
            ->where('d.employment_id', $employment)->groupBy('d.id')->selectRaw('sum(l.percentage) as t')->pluck('t')->map(fn ($t) => (string) $t)->unique()->values()->all();
        expect($totals)->toBe(['100.00']);
    });
    $audit = TenancyScope::runAs(SCC_TENANT, fn () => DB::table('audit_events')->where('action', 'employment.cost_distribution_changed')->where('entity_id', $employment)->count());
    expect($audit)->toBe(2);
});

test('un centro cerrado, archivado, aún no abierto o de otra empresa no entra en una distribución nueva', function (): void {
    $employment = TenancyScope::runAs(SCC_TENANT, fn (): string => EmploymentCostDistributionFactory::employment($this->lima));
    $path = "/employments/{$employment}/cost-distributions";
    $closed = sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'CER', 'name' => 'Cerrado'])->json('data.id');
    sccCall($this->admin, 'POST', "/cost-centers/{$closed}/close", ['closed_on' => '2026-02-28'])->assertOk();
    $future = sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'FUT', 'name' => 'Futuro', 'opened_on' => '2027-01-01'])->json('data.id');
    $other = sccCall($this->admin, 'POST', '/cost-centers', ['legal_entity_id' => $this->sur, 'code' => 'SUR', 'name' => 'Del sur'])->json('data.id');

    foreach ([$closed, $future, $other] as $center) {
        $r = sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-03-01', 'lines' => [['cost_center_id' => $center, 'percentage' => '100']]]);
        $r->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        expect(sccFields($r))->toContain('lines.0.cost_center_id');
    }
    // Antes del ingreso tampoco.
    $ok = sccCall($this->admin, 'POST', '/cost-centers', ['code' => 'OK', 'name' => 'Abierto'])->json('data.id');
    sccCall($this->admin, 'POST', $path, ['valid_from' => '2024-01-01', 'lines' => [['cost_center_id' => $ok, 'percentage' => '100']]])->assertStatus(422);

    // Con una distribución vigente, el centro no se cierra ni se archiva.
    sccCall($this->admin, 'POST', $path, ['valid_from' => '2026-03-01', 'lines' => [['cost_center_id' => $ok, 'percentage' => '100']]])->assertCreated();
    sccCall($this->admin, 'POST', "/cost-centers/{$ok}/close", ['closed_on' => '2026-12-31'])->assertStatus(422)->assertJsonPath('error.meta.rule', 'ORG-4');
    sccCall($this->admin, 'POST', "/cost-centers/{$ok}/archive", [])->assertStatus(422);
});

test('la base rechaza una distribución que no suma 100 % aunque se escriba por fuera de la API', function (): void {
    TenancyScope::runAs(SCC_TENANT, function (): void {
        $employment = EmploymentCostDistributionFactory::employment($this->lima);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        expect(function () use ($employment): void {
            DB::transaction(function () use ($employment): void {
                DB::table('employment_cost_distributions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => SCC_TENANT, 'employment_id' => $employment, 'valid_from' => '2026-01-01']);
            });
        })->toThrow(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    });
    TenancyScope::runAs(SCC_TENANT, function (): void {
        $employment = EmploymentCostDistributionFactory::employment($this->lima);
        $center = CostCenter::factory()->create();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        expect(function () use ($employment, $center): void {
            DB::transaction(function () use ($employment, $center): void {
                $id = (string) Str::uuid();
                DB::table('employment_cost_distributions')->insert(['id' => $id, 'tenant_id' => SCC_TENANT, 'employment_id' => $employment, 'valid_from' => '2026-01-01']);
                DB::table('employment_cost_distribution_lines')->insert(['tenant_id' => SCC_TENANT, 'employment_cost_distribution_id' => $id, 'cost_center_id' => $center->id, 'percentage' => '90']);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        })->toThrow(QueryException::class);
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    });
});

test('la distribución de una relación de otra organización o fuera de alcance responde 404', function (): void {
    $employment = TenancyScope::runAs(SCC_TENANT, fn (): string => EmploymentCostDistributionFactory::employment($this->sur));
    $intruder = sccUser(SCC_OTHER, 'admin_empresa');
    sccCall($intruder, 'GET', "/employments/{$employment}/cost-distributions")->assertNotFound();
    sccCall($intruder, 'POST', "/employments/{$employment}/cost-distributions", ['valid_from' => '2026-01-01', 'lines' => []])->assertNotFound();

    $hrLima = sccUser(SCC_TENANT, 'rrhh', 'legal_entity', [$this->lima]);
    sccCall($hrLima, 'GET', "/employments/{$employment}/cost-distributions")->assertNotFound();
    $employee = sccUser(SCC_TENANT, 'empleado');
    sccCall($employee, 'POST', "/employments/{$employment}/cost-distributions", [])->assertStatus(403);
});

test('los porcentajes se manejan en centésimas exactas', function (): void {
    expect(CostDistribution::hundredths('33.33'))->toBe(3333)
        ->and(CostDistribution::hundredths(40))->toBe(4000)
        ->and(CostDistribution::hundredths(12.5))->toBe(1250)
        ->and(CostDistribution::hundredths('1.234'))->toBeNull()
        ->and(CostDistribution::hundredths('-5'))->toBeNull()
        ->and(CostDistribution::format(3334))->toBe('33.34')
        ->and(CostDistribution::errors([['cost_center_id' => 'a', 'hundredths' => 5000], ['cost_center_id' => 'b', 'hundredths' => 5000]]))->toBe([])
        ->and(CostDistribution::errors([['cost_center_id' => 'a', 'hundredths' => 9999]]))->toBe(['total'])
        ->and(CostDistribution::errors([]))->toBe(['empty']);
});

test('el modelo de sede y de centro de costo usan el aislamiento por organización', function (): void {
    $a = TenancyScope::runAs(SCC_TENANT, fn () => Site::factory()->create()->id);
    expect(TenancyScope::runAs(SCC_OTHER, fn () => Site::query()->find($a)))->toBeNull();
});
