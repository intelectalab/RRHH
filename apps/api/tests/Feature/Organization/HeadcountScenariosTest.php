<?php

/**
 * POS-5 · Escenarios de planificación: copian la dotación a una fecha, suman, congelan o quitan plazas sin tocar las
 * posiciones reales (RN-POS-08) y se comparan lado a lado en plazas y costo. Solo aplicar un escenario aprobado crea
 * solicitudes de posición en borrador y congela o cierra las posiciones marcadas; lo que no procede (una posición
 * ocupada no se cierra) queda en el resumen con su motivo. El costo es sensible y su lectura queda registrada.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Domain\HeadcountScenarioStateMachine;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const HSC_TENANT = 'aaaaaaaa-0b05-4000-8000-00000000000a';
const HSC_OTHER = 'bbbbbbbb-0b05-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([HSC_TENANT => 'hsc-org', HSC_OTHER => 'hsc-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = hscUser(HSC_TENANT, 'admin_empresa');
    $this->auditor = hscUser(HSC_TENANT, 'auditor');
    $this->employee = hscUser(HSC_TENANT, 'empleado');
    $this->other = hscUser(HSC_OTHER, 'admin_empresa');
    TenancyScope::runAs(HSC_TENANT, function (): void {
        $this->entity = LegalEntity::factory()->create()->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $base = ['legal_entity_id' => $this->entity, 'org_unit_id' => $this->unit, 'job_profile_id' => $this->profile, 'budgeted_cost' => '2500.00', 'currency' => 'PEN'];
        $this->open = Position::factory()->create($base + ['code' => 'HS-1', 'status' => 'open'])->id;
        $this->open2 = Position::factory()->create($base + ['code' => 'HS-2', 'status' => 'open'])->id;
        $this->filled = Position::factory()->create($base + ['code' => 'HS-3', 'status' => 'filled'])->id;
    });
});

afterEach(fn () => TenancyScope::clear());

function hscUser(string $tenant, string $role): User
{
    return TenancyScope::runAs($tenant, function () use ($role): User {
        $u = User::factory()->create();
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function hscCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

function hscPositionStatus(string $id): string
{
    return (string) TenancyScope::runAs(HSC_TENANT, fn () => DB::table('positions')->where('id', $id)->value('status'));
}

test('POS-5 · el escenario copia la dotación, se compara y solo al aplicarse con aprobación cambia lo real', function (): void {
    $current = hscCall($this->admin, 'POST', '/headcount-scenarios', ['name' => 'Actual', 'kind' => 'current', 'legal_entity_id' => $this->entity, 'base_as_of' => '2026-03-01']);
    $this->assertMatchesOpenApi($current, 'post', '/headcount-scenarios', 201);
    expect($current->json('data.totals.planned'))->toBe(3)
        ->and($current->json('data.totals.occupied'))->toBe(1)
        ->and($current->json('data.projected_cost.amount'))->not->toBeNull();

    $growth = hscCall($this->admin, 'POST', '/headcount-scenarios', ['name' => 'Crecimiento 2026', 'kind' => 'growth', 'legal_entity_id' => $this->entity,
        'base_as_of' => '2026-03-01', 'assumptions' => ['nota' => 'Nueva sede en Arequipa']]);
    $growth->assertCreated();
    $id = $growth->json('data.id');
    $lines = collect($growth->json('data.lines'));
    expect($lines)->toHaveCount(3)->and($lines->pluck('change')->unique()->all())->toBe(['baseline'])
        ->and($growth->json('data.assumptions.nota'))->toBe('Nueva sede en Arequipa')
        ->and($growth->json('data.base_as_of'))->toBe('2026-03-01');

    $add = hscCall($this->admin, 'POST', "/headcount-scenarios/{$id}/lines", ['org_unit_id' => $this->unit, 'job_profile_id' => $this->profile,
        'planned_count' => 2, 'title' => 'Analista de ventas', 'planned_start_on' => '2026-06-01', 'projected_cost' => ['amount' => '3000.00', 'currency' => 'PEN']]);
    $this->assertMatchesOpenApi($add, 'post', '/headcount-scenarios/{id}/lines', 201);
    $byPosition = $lines->keyBy('position_id');
    $freeze = hscCall($this->admin, 'PATCH', '/headcount-scenario-lines/'.$byPosition[$this->open]['id'], ['change' => 'freeze']);
    $this->assertMatchesOpenApi($freeze, 'patch', '/headcount-scenario-lines/{id}', 200);
    hscCall($this->admin, 'PATCH', '/headcount-scenario-lines/'.$byPosition[$this->filled]['id'], ['change' => 'remove'])->assertOk();
    // Una posición real no se «agrega».
    hscCall($this->admin, 'PATCH', '/headcount-scenario-lines/'.$byPosition[$this->open2]['id'], ['change' => 'add'])->assertStatus(422);

    $compare = hscCall($this->admin, 'GET', "/headcount-scenarios/compare?ids={$current->json('data.id')},{$id}");
    $this->assertMatchesOpenApi($compare, 'get', '/headcount-scenarios/compare', 200);
    [$a, $b] = $compare->json('data.scenarios');
    expect($a['totals']['planned'])->toBe(3)
        ->and($b['totals']['planned'])->toBe(4)
        ->and($b['totals']['added'])->toBe(2)
        ->and($b['totals']['removed'])->toBe(1)
        ->and($b['totals']['frozen'])->toBe(1)
        ->and($b['projected_cost'])->not->toBeNull()
        ->and($compare->json('data.units.0.scenarios'))->toHaveKeys([$a['id'], $b['id']]);

    // RN-POS-08: nada real cambió todavía.
    expect(hscPositionStatus($this->open))->toBe('open')
        ->and(TenancyScope::runAs(HSC_TENANT, fn () => DB::table('position_requests')->count()))->toBe(0);

    $applyDraft = hscCall($this->admin, 'POST', "/headcount-scenarios/{$id}/apply");
    $this->assertMatchesOpenApi($applyDraft, 'post', '/headcount-scenarios/{id}/apply', 409);
    $applyDraft->assertJsonPath('error.code', 'invalid_transition');

    $this->assertMatchesOpenApi(hscCall($this->admin, 'POST', "/headcount-scenarios/{$id}/submit"), 'post', '/headcount-scenarios/{id}/submit', 200);
    // En revisión ya no se edita.
    hscCall($this->admin, 'POST', "/headcount-scenarios/{$id}/lines", ['org_unit_id' => $this->unit, 'job_profile_id' => $this->profile])->assertStatus(409);
    $this->assertMatchesOpenApi(hscCall($this->admin, 'POST', "/headcount-scenarios/{$id}/approve"), 'post', '/headcount-scenarios/{id}/approve', 200);
    expect(hscPositionStatus($this->open))->toBe('open');

    $applied = hscCall($this->admin, 'POST', "/headcount-scenarios/{$id}/apply");
    $this->assertMatchesOpenApi($applied, 'post', '/headcount-scenarios/{id}/apply', 200);
    $summary = $applied->json('data.applied_summary');
    expect($applied->json('data.status'))->toBe('applied')
        ->and($summary['position_requests'])->toHaveCount(1)
        ->and($summary['frozen'])->toBe([$this->open])
        ->and($summary['skipped'][0]['position_id'])->toBe($this->filled)
        ->and(hscPositionStatus($this->open))->toBe('frozen')
        ->and(hscPositionStatus($this->filled))->toBe('filled');
    $request = TenancyScope::runAs(HSC_TENANT, fn () => DB::table('position_requests')->where('id', $summary['position_requests'][0])->first());
    expect($request->status)->toBe('draft')->and((int) $request->quantity)->toBe(2);

    expect(TenancyScope::runAs(HSC_TENANT, fn () => DB::table('audit_events')->where('entity_type', 'headcount_scenario')->where('entity_id', $id)->where('action', 'headcount_scenario.apply')->exists()))->toBeTrue();
});

test('POS-5 · sin permiso de presupuesto el costo no viaja; con él, su lectura queda registrada; sin permiso, 403', function (): void {
    $created = hscCall($this->admin, 'POST', '/headcount-scenarios', ['name' => 'Conservador', 'kind' => 'conservative', 'legal_entity_id' => $this->entity, 'base_as_of' => '2026-03-01']);
    $id = $created->json('data.id');
    expect(TenancyScope::runAs(HSC_TENANT, fn () => DB::table('data_access_logs')->where('entity_id', $id)->exists()))->toBeTrue();

    $seen = hscCall($this->auditor, 'GET', "/headcount-scenarios/{$id}");
    $this->assertMatchesOpenApi($seen, 'get', '/headcount-scenarios/{id}', 200);
    expect($seen->json('data'))->not->toHaveKey('projected_cost')
        ->and(collect($seen->json('data.lines'))->first())->not->toHaveKey('projected_cost');

    $denied = hscCall($this->employee, 'GET', '/headcount-scenarios');
    $this->assertMatchesOpenApi($denied, 'get', '/headcount-scenarios', 403);
    hscCall($this->auditor, 'POST', '/headcount-scenarios', ['name' => 'X', 'kind' => 'custom'])->assertForbidden();

    $invalid = hscCall($this->admin, 'POST', '/headcount-scenarios', ['name' => '', 'kind' => 'otro']);
    $this->assertMatchesOpenApi($invalid, 'post', '/headcount-scenarios', 422);
    $this->assertMatchesOpenApi(hscCall($this->admin, 'GET', '/headcount-scenarios/compare?ids=x'), 'get', '/headcount-scenarios/compare', 400);
});

test('POS-5 · otra organización no ve el escenario', function (): void {
    $id = hscCall($this->admin, 'POST', '/headcount-scenarios', ['name' => 'Actual', 'kind' => 'current', 'legal_entity_id' => $this->entity])->json('data.id');
    $this->assertMatchesOpenApi(hscCall($this->other, 'GET', "/headcount-scenarios/{$id}"), 'get', '/headcount-scenarios/{id}', 404);
    hscCall($this->other, 'POST', "/headcount-scenarios/{$id}/archive")->assertNotFound();
});

test('POS-5 · matriz de transiciones del escenario', function (): void {
    $machine = new HeadcountScenarioStateMachine;
    $states = ['draft', 'in_review', 'approved', 'applied', 'archived'];
    $actions = ['submit', 'approve', 'return', 'apply', 'archive'];
    $allowed = ['draft' => ['submit', 'archive'], 'in_review' => ['approve', 'return', 'archive'], 'approved' => ['apply', 'return', 'archive'], 'applied' => ['archive'], 'archived' => []];
    foreach ($states as $s) {
        foreach ($actions as $a) {
            expect($machine->can($s, $a))->toBe(in_array($a, $allowed[$s], true), "{$s} × {$a}");
        }
    }
});
