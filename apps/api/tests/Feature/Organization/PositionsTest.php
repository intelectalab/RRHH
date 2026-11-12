<?php

/**
 * POS-1 · Posiciones como plazas: muchas posiciones comparten un puesto, la línea de reporte se deriva
 * de la posición jefe (o del responsable de la unidad, ORG-3) y la posición guarda la historia de sus
 * ocupantes. RN-POS-02 (un ocupante, o tantos como cupos si es compartida).
 * POS-2 · Estados de la posición (ME-03): solo por acciones, ocupada / vacante derivada de las
 * asignaciones vigentes, congelar impide abrir vacante (RN-POS-03), no se cierra ocupada (RN-POS-06)
 * y cada transición queda auditada.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Application\PositionService;
use App\Modules\Organization\Database\Factories\EmploymentCostDistributionFactory;
use App\Modules\Organization\Domain\PositionStateMachine;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const POS_TENANT = 'aaaaaaaa-0b05-4000-8000-00000000000a';
const POS_OTHER = 'bbbbbbbb-0b05-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([POS_TENANT => 'pos-org', POS_OTHER => 'pos-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = posUser(POS_TENANT, 'admin_empresa');
    TenancyScope::runAs(POS_TENANT, function (): void {
        $this->entity = LegalEntity::factory()->create()->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
});

afterEach(fn () => TenancyScope::clear());

function posUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function posCall(User $user, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()] + $headers)
        ->json($method, '/api/v1'.$path, $body);
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function posBody(array $extra = []): array
{
    return $extra + [
        'code' => 'p-'.Str::lower(Str::random(6)),
        'title' => 'Analista contable',
        'job_profile_id' => test()->profile,
        'legal_entity_id' => test()->entity,
        'org_unit_id' => test()->unit,
        'site_id' => test()->site,
        'valid_from' => '2026-01-01',
    ];
}

/** Crea una posición por API y la deja en el estado pedido (aprobada por defecto). */
function posCreate(array $extra = [], string $status = 'approved'): string
{
    $res = posCall(test()->admin, 'POST', '/positions', posBody($extra));
    $res->assertCreated();
    $id = (string) $res->json('data.id');
    if ($status !== 'proposed') {
        posCall(test()->admin, 'POST', "/positions/{$id}/approve")->assertOk();
    }
    if ($status === 'open' || $status === 'frozen') {
        TenancyScope::runAs(POS_TENANT, fn () => DB::table('positions')->where('id', $id)->update(['status' => $status]));
    }

    return $id;
}

/** Ocupante: relación laboral nueva asignada a la posición desde una fecha (HIS aún no expone API). */
function posOccupy(string $positionId, string $from = '2026-01-01', ?string $to = null, string $name = 'Prueba'): string
{
    return TenancyScope::runAs(POS_TENANT, fn (): string => DB::transaction(function () use ($positionId, $from, $to, $name): string {
        $employment = EmploymentCostDistributionFactory::employment(test()->entity);
        DB::table('people')->whereIn('id', DB::table('employments')->where('id', $employment)->select('person_id'))->update(['first_names' => $name]);
        DB::table('employment_assignments')->insert([
            'tenant_id' => POS_TENANT, 'employment_id' => $employment, 'job_profile_id' => test()->profile,
            'position_id' => $positionId, 'org_unit_id' => test()->unit, 'site_id' => test()->site,
            'valid_from' => $from, 'valid_to' => $to, 'reason' => 'Ingreso',
        ]);
        app(PositionOccupancy::class)->sync($positionId);

        return $employment;
    }));
}

// ---------------------------------------------------------------- POS-1 · Plazas

test('se crea una posición propuesta con puesto, unidad, sede y vigencia; varias comparten el mismo puesto', function (): void {
    $res = posCall($this->admin, 'POST', '/positions', posBody(['code' => 'cont-01', 'is_critical' => true]));
    $res->assertCreated();
    $this->assertMatchesOpenApi($res, 'post', '/positions', 201);
    expect($res->json('data'))->toMatchArray([
        'code' => 'CONT-01', 'status' => 'proposed', 'seats' => 1, 'is_shared' => false, 'occupied_seats' => 0,
        'job_profile_id' => $this->profile, 'org_unit_id' => $this->unit, 'valid_from' => '2026-01-01',
        'allowed_actions' => ['approve', 'reject'],
    ]);
    expect($res->json('data.job_profile_name'))->not->toBeNull();

    posCall($this->admin, 'POST', '/positions', posBody(['code' => 'cont-02']))->assertCreated();
    $list = posCall($this->admin, 'GET', '/positions?filter[job_profile_id]='.$this->profile);
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/positions', 200);
    expect($list->json('meta.total'))->toBe(2);
    $profile = posCall($this->admin, 'GET', '/job-profiles/'.$this->profile);
    expect($profile->json('data.positions_count'))->toBe(2);

    $show = posCall($this->admin, 'GET', '/positions/'.$res->json('data.id'));
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/positions/{id}', 200);
    expect(DB::table('audit_events')->where('action', 'position.created')->where('entity_id', $res->json('data.id'))->exists())->toBeTrue();
});

test('datos inválidos y estructura no disponible responden 422; código repetido también', function (): void {
    $bad = posCall($this->admin, 'POST', '/positions', ['title' => 'X']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/positions', 422);

    posCall($this->admin, 'POST', '/positions', posBody(['code' => 'dup-1']))->assertCreated();
    posCall($this->admin, 'POST', '/positions', posBody(['code' => 'DUP-1']))->assertStatus(422)->assertJsonPath('error.details.0.field', 'code');

    // Puesto archivado (RN-ORG-04), unidad archivada y sede de otra empresa.
    $archivedProfile = TenancyScope::runAs(POS_TENANT, fn () => JobProfile::factory()->create(['status' => 'inactive'])->id);
    $archivedUnit = TenancyScope::runAs(POS_TENANT, fn () => OrgUnit::factory()->create(['status' => 'archived'])->id);
    $otherSite = TenancyScope::runAs(POS_TENANT, fn () => Site::factory()->create(['legal_entity_id' => LegalEntity::factory()->create()->id])->id);
    $res = posCall($this->admin, 'POST', '/positions', posBody(['job_profile_id' => $archivedProfile, 'org_unit_id' => $archivedUnit, 'site_id' => $otherSite]));
    $res->assertStatus(422);
    $fields = collect($res->json('error.details'))->pluck('field')->all();
    expect($fields)->toContain('job_profile_id', 'org_unit_id', 'site_id');

    // Solo una posición compartida tiene más de un cupo.
    posCall($this->admin, 'POST', '/positions', posBody(['seats' => 3]))->assertStatus(422)->assertJsonPath('error.details.0.field', 'seats');
    posCall($this->admin, 'POST', '/positions', posBody(['valid_from' => '2026-05-01', 'valid_to' => '2026-01-01']))->assertStatus(422);
});

test('sin permiso responde 403; con el módulo apagado, 403 module_not_enabled', function (): void {
    $employee = posUser(POS_TENANT, 'empleado');
    $res = posCall($employee, 'GET', '/positions');
    $res->assertForbidden();
    $this->assertMatchesOpenApi($res, 'get', '/positions', 403);

    $manager = posUser(POS_TENANT, 'manager');
    posCall($manager, 'POST', '/positions', posBody())->assertForbidden();

    IdentityFixtures::disableModule(POS_TENANT, 'core');
    posCall($this->admin, 'GET', '/positions')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
});

test('otra organización no ve, no edita ni cambia el estado de la posición (404)', function (): void {
    $id = posCreate();
    $other = posUser(POS_OTHER, 'admin_empresa');
    posCall($other, 'GET', "/positions/{$id}")->assertNotFound();
    posCall($other, 'PATCH', "/positions/{$id}", ['title' => 'Otra'])->assertNotFound();
    posCall($other, 'POST', "/positions/{$id}/freeze", ['reason' => 'x'])->assertNotFound();
    posCall($other, 'GET', "/positions/{$id}/occupants")->assertNotFound();
    expect(collect(posCall($other, 'GET', '/positions')->json('data'))->pluck('id')->all())->not->toContain($id);
});

test('la línea de reporte se deriva de la posición jefe o del responsable de la unidad, sin ciclos', function (): void {
    $director = posCreate(['title' => 'Director']);
    $jefe = posCreate(['title' => 'Jefe', 'reports_to_position_id' => $director]);
    $analista = posCreate(['title' => 'Analista', 'reports_to_position_id' => $jefe]);
    posOccupy($director, '2026-01-01', null, 'Rosa');

    $line = posCall($this->admin, 'GET', "/positions/{$analista}/reporting-line");
    $line->assertOk();
    $this->assertMatchesOpenApi($line, 'get', '/positions/{id}/reporting-line', 200);
    expect(collect($line->json('data'))->pluck('position_id')->all())->toBe([$jefe, $director])
        ->and($line->json('data.1.occupants.0.person_name'))->toStartWith('Rosa')
        ->and($line->json('data.0.source'))->toBe('position');

    // Sin posición jefe: reporta al responsable de su unidad (ORG-3) o de la unidad superior.
    TenancyScope::runAs(POS_TENANT, function () use ($director): void {
        $parent = OrgUnit::factory()->create(['legal_entity_id' => $this->entity, 'head_position_id' => $director]);
        DB::table('org_units')->where('id', $this->unit)->update(['parent_id' => $parent->id]);
    });
    $suelta = posCreate(['title' => 'Asistente']);
    $res = posCall($this->admin, 'GET', "/positions/{$suelta}");
    expect($res->json('data.effective_reports_to_position_id'))->toBe($director);
    $line = posCall($this->admin, 'GET', "/positions/{$suelta}/reporting-line");
    expect($line->json('data.0'))->toMatchArray(['position_id' => $director, 'source' => 'unit_head']);
    expect(posCall($this->admin, 'GET', "/positions/{$director}")->json('data.heads_units.0.id'))->not->toBeNull();

    // Un ciclo en la línea de reporte se rechaza.
    $cycle = posCall($this->admin, 'PATCH', "/positions/{$director}", ['reports_to_position_id' => $analista]);
    $cycle->assertStatus(422)->assertJsonPath('error.details.0.field', 'reports_to_position_id');
    $this->assertMatchesOpenApi($cycle, 'patch', '/positions/{id}', 422);
    posCall($this->admin, 'PATCH', "/positions/{$director}", ['reports_to_position_id' => $director])->assertStatus(422);
});

test('la posición guarda la historia de sus ocupantes y ocupada / vacante se deriva de las asignaciones', function (): void {
    $id = posCreate();
    $first = posOccupy($id, '2026-01-01', '2026-03-31', 'Ana');
    // El reemplazo entra cuando sale la primera ocupante.
    posOccupy($id, '2026-04-01', null, 'Luis');

    $history = posCall($this->admin, 'GET', "/positions/{$id}/occupants");
    $history->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/positions/{id}/occupants', 200);
    expect(collect($history->json('data'))->pluck('person_name')->map(fn ($n) => explode(' ', $n)[0])->all())->toBe(['Luis', 'Ana'])
        ->and($history->json('data.1.employment_id'))->toBe($first);
    $march = posCall($this->admin, 'GET', "/positions/{$id}/occupants?as_of=2026-02-15");
    expect(collect($march->json('data'))->pluck('person_name')->map(fn ($n) => explode(' ', $n)[0])->all())->toBe(['Ana']);
    posCall($this->admin, 'GET', "/positions/{$id}/occupants?as_of=ayer")->assertStatus(400);

    $show = posCall($this->admin, 'GET', "/positions/{$id}");
    expect($show->json('data.status'))->toBe('filled')->and($show->json('data.occupied_seats'))->toBe(1)
        ->and($show->json('data.allowed_actions'))->toBe([]);

    // RN-POS-04: al cesar el ocupante, la posición vuelve a vacante.
    TenancyScope::runAs(POS_TENANT, fn () => DB::table('employment_assignments')->where('position_id', $id)->whereNull('valid_to')->update(['valid_to' => now()->subDay()->toDateString()]));
    Artisan::call('positions:sync-occupancy');
    TenancyScope::clear();
    expect(posCall($this->admin, 'GET', "/positions/{$id}")->json('data.status'))->toBe('open');
    $events = posCall($this->admin, 'GET', "/positions/{$id}/status-events");
    $this->assertMatchesOpenApi($events, 'get', '/positions/{id}/status-events', 200);
    expect(collect($events->json('data'))->pluck('action')->all())->toContain('fill', 'vacate', 'approve', 'create');
    expect(DB::table('outbox_events')->where('event_type', 'position.vacated.v1')->where('aggregate_id', $id)->exists())->toBeTrue();
});

test('RN-POS-02: una posición admite un ocupante, o tantos como cupos si es compartida', function (): void {
    $single = posCreate();
    posOccupy($single);
    $occupancy = app(PositionOccupancy::class);
    TenancyScope::runAs(POS_TENANT, function () use ($single, $occupancy): void {
        expect(fn () => $occupancy->assertAssignable($single, '2026-06-01'))->toThrow(ValidationException::class);
    });
    // La base también lo impide (disparador de cupos).
    expect(fn () => posOccupy($single, '2026-06-01'))->toThrow(QueryException::class);

    $shared = posCreate(['is_shared' => true, 'seats' => 2, 'title' => 'Operario']);
    posOccupy($shared);
    expect(posCall($this->admin, 'GET', "/positions/{$shared}")->json('data'))->toMatchArray(['status' => 'approved', 'occupied_seats' => 1]);
    posOccupy($shared);
    expect(posCall($this->admin, 'GET', "/positions/{$shared}")->json('data'))->toMatchArray(['status' => 'filled', 'occupied_seats' => 2]);
    expect(fn () => posOccupy($shared))->toThrow(QueryException::class);
    posCall($this->admin, 'PATCH', "/positions/{$shared}", ['seats' => 1])->assertStatus(422)->assertJsonPath('error.details.0.field', 'seats');
});

// ---------------------------------------------------------------- POS-2 · Estados

test('congelar una posición impide abrir una vacante sobre ella (RN-POS-03) y cada transición queda auditada', function (): void {
    $id = posCreate();
    $freeze = posCall($this->admin, 'POST', "/positions/{$id}/freeze", ['reason' => 'Reestructuración', 'effective_on' => '2026-02-01']);
    $freeze->assertOk();
    $this->assertMatchesOpenApi($freeze, 'post', '/positions/{id}/freeze', 200);
    expect($freeze->json('data'))->toMatchArray(['status' => 'frozen', 'allowed_actions' => ['unfreeze', 'close']]);

    $open = posCall($this->admin, 'POST', "/positions/{$id}/open-recruitment");
    $open->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($open, 'post', '/positions/{id}/open-recruitment', 409);
    TenancyScope::runAs(POS_TENANT, function () use ($id): void {
        expect(fn () => app(PositionOccupancy::class)->assertAssignable($id, '2026-03-01'))->toThrow(ValidationException::class);
    });

    posCall($this->admin, 'POST', "/positions/{$id}/unfreeze")->assertOk()->assertJsonPath('data.status', 'open');
    $rec = posCall($this->admin, 'POST', "/positions/{$id}/open-recruitment");
    $rec->assertOk()->assertJsonPath('data.status', 'recruiting');
    $this->assertMatchesOpenApi($rec, 'post', '/positions/{id}/open-recruitment', 200);
    posCall($this->admin, 'POST', "/positions/{$id}/cancel-recruitment")->assertOk()->assertJsonPath('data.status', 'open');

    // Congelar exige motivo.
    posCall($this->admin, 'POST', "/positions/{$id}/freeze")->assertStatus(422)->assertJsonPath('error.details.0.field', 'reason');

    $events = DB::table('position_status_events')->where('position_id', $id)->orderBy('created_at')->pluck('action')->all();
    expect($events)->toBe(['create', 'approve', 'freeze', 'unfreeze', 'open-recruitment', 'cancel-recruitment']);
    expect(DB::table('position_status_events')->where('position_id', $id)->where('action', 'freeze')->value('effective_on'))->toBe('2026-02-01');
    foreach (['position.approve', 'position.freeze', 'position.unfreeze', 'position.open_recruitment', 'position.cancel_recruitment'] as $action) {
        expect(DB::table('audit_events')->where('action', $action)->where('entity_id', $id)->exists())->toBeTrue();
    }
    expect(DB::table('outbox_events')->where('event_type', 'position.frozen.v1')->where('aggregate_id', $id)->exists())->toBeTrue();

    // El historial de estados no se puede alterar.
    expect(fn () => DB::table('position_status_events')->where('position_id', $id)->update(['reason' => 'x']))->toThrow(QueryException::class);
});

test('no se cierra una posición ocupada (RN-POS-06), ni la responsable de una unidad; cerrada no se edita', function (): void {
    $id = posCreate();
    posOccupy($id);
    $close = posCall($this->admin, 'POST', "/positions/{$id}/close", ['reason' => 'Ya no se necesita']);
    $close->assertStatus(409);
    $this->assertMatchesOpenApi($close, 'post', '/positions/{id}/close', 409);

    $shared = posCreate(['is_shared' => true, 'seats' => 3]);
    posOccupy($shared);
    posCall($this->admin, 'POST', "/positions/{$shared}/close", ['reason' => 'x'])->assertStatus(422)->assertJsonPath('error.code', 'business_rule_violated');

    $head = posCreate(['title' => 'Gerente']);
    TenancyScope::runAs(POS_TENANT, fn () => DB::table('org_units')->where('id', $this->unit)->update(['head_position_id' => $head]));
    posCall($this->admin, 'POST', "/positions/{$head}/close", ['reason' => 'x'])->assertStatus(422);

    $free = posCreate(['title' => 'Temporal']);
    posCall($this->admin, 'POST', "/positions/{$free}/close")->assertStatus(422);
    $ok = posCall($this->admin, 'POST', "/positions/{$free}/close", ['reason' => 'Fin del proyecto']);
    $ok->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.allowed_actions', []);
    $this->assertMatchesOpenApi($ok, 'post', '/positions/{id}/close', 200);
    $edit = posCall($this->admin, 'PATCH', "/positions/{$free}", ['title' => 'Otra']);
    $edit->assertStatus(409);
    $this->assertMatchesOpenApi($edit, 'patch', '/positions/{id}', 409);
    TenancyScope::runAs(POS_TENANT, function () use ($free): void {
        expect(fn () => app(PositionOccupancy::class)->assertAssignable($free, '2026-03-01'))->toThrow(ValidationException::class);
    });
});

test('el estado no se edita por PATCH y la propuesta se aprueba o rechaza con motivo', function (): void {
    $id = posCreate([], 'proposed');
    $patch = posCall($this->admin, 'PATCH', "/positions/{$id}", ['status' => 'filled', 'title' => 'Nuevo nombre']);
    $patch->assertOk();
    $this->assertMatchesOpenApi($patch, 'patch', '/positions/{id}', 200);
    expect($patch->json('data'))->toMatchArray(['status' => 'proposed', 'title' => 'Nuevo nombre']);

    $manager = posUser(POS_TENANT, 'manager');
    posCall($manager, 'POST', "/positions/{$id}/approve")->assertForbidden();
    posCall($this->admin, 'POST', "/positions/{$id}/reject")->assertStatus(422);
    $reject = posCall($this->admin, 'POST', "/positions/{$id}/reject", ['reason' => 'Sin presupuesto']);
    $reject->assertOk()->assertJsonPath('data.status', 'closed');
    $this->assertMatchesOpenApi($reject, 'post', '/positions/{id}/reject', 200);
    posCall($this->admin, 'POST', "/positions/{$id}/approve")->assertStatus(409);
});

test('matriz de la máquina de estados: cada estado × cada acción', function (): void {
    $machine = new PositionStateMachine;
    $states = ['proposed', 'approved', 'open', 'recruiting', 'filled', 'frozen', 'closed'];
    $actions = ['approve', 'reject', 'open-recruitment', 'cancel-recruitment', 'fill', 'vacate', 'freeze', 'unfreeze', 'close'];
    $expected = [
        'proposed' => ['approve' => 'approved', 'reject' => 'closed'],
        'approved' => ['open-recruitment' => 'recruiting', 'fill' => 'filled', 'freeze' => 'frozen', 'close' => 'closed'],
        'open' => ['open-recruitment' => 'recruiting', 'fill' => 'filled', 'freeze' => 'frozen', 'close' => 'closed'],
        'recruiting' => ['fill' => 'filled', 'cancel-recruitment' => 'open'],
        'filled' => ['vacate' => 'open'],
        'frozen' => ['unfreeze' => 'open', 'close' => 'closed'],
        'closed' => [],
    ];
    foreach ($states as $state) {
        foreach ($actions as $action) {
            if (isset($expected[$state][$action])) {
                expect($machine->getNextState($state, $action))->toBe($expected[$state][$action]);
            } else {
                expect(fn () => $machine->getNextState($state, $action))->toThrow(InvalidTransitionException::class);
            }
        }
        expect(array_intersect($machine->manualActions($state), PositionStateMachine::SYSTEM_ACTIONS))->toBe([]);
    }

    // Las acciones del sistema no tienen ruta: ocupar o liberar a mano no existe.
    $id = posCreate();
    posCall($this->admin, 'POST', "/positions/{$id}/fill")->assertNotFound();
    expect(app(PositionService::class))->toBeInstanceOf(PositionService::class);
});

test('el costo presupuestado es sensible: sin permiso no viaja ni se registra, y su lectura queda registrada', function (): void {
    $id = posCreate(['budget' => ['amount' => '4500.00', 'currency' => 'PEN']]);
    $hr = posCall($this->admin, 'GET', "/positions/{$id}");
    expect($hr->json('data.budget'))->toBe(['amount' => '4500.00', 'currency' => 'PEN']);
    expect(DB::table('data_access_logs')->where('entity_type', 'Position')->where('entity_id', $id)->exists())->toBeTrue();

    $auditor = posUser(POS_TENANT, 'auditor');
    $res = posCall($auditor, 'GET', "/positions/{$id}");
    $res->assertOk();
    expect(array_key_exists('budget', (array) $res->json('data')))->toBeFalse();
    posCall($auditor, 'GET', '/positions?sort=-budget')->assertStatus(400);
    posCall($this->admin, 'GET', '/positions?sort=-budget')->assertOk();

    $recruiterless = IdentityFixtures::userWithPermissions(POS_TENANT, ['org.positions.view', 'org.positions.create']);
    posCall($recruiterless, 'POST', '/positions', posBody(['budget' => ['amount' => '1.00', 'currency' => 'PEN']]))
        ->assertStatus(422)->assertJsonPath('error.details.0.field', 'budget');
});
