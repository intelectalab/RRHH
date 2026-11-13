<?php

/**
 * HIS-2 · Movimientos de personal: la única vía para cambiar la asignación, con aprobación según la
 * configuración (movimientos.tipos_con_aprobacion) o porque lo propone un jefe; la promoción queda identificada.
 * HIS-4 · Cambios con fecha futura (programados, editables y anulables antes de su fecha, aplicados solos) y
 * correcciones retroactivas (RN-HIS-05/06): rango afectado en un evento y original reemplazado, nunca borrado.
 */

use App\Modules\CoreHR\Application\PersonnelActions;
use App\Modules\CoreHR\Domain\PersonnelActionStateMachine;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const MOV_TENANT = 'aaaaaaaa-0b02-4000-8000-00000000000a';
const MOV_OTHER = 'bbbbbbbb-0b02-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([MOV_TENANT => 'mov-org', MOV_OTHER => 'mov-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = movUser(MOV_TENANT, 'admin_empresa');
    $this->rrhh = movUser(MOV_TENANT, 'rrhh');
    TenancyScope::runAs(MOV_TENANT, function (): void {
        $entity = LegalEntity::factory()->create();
        DB::table('legal_entity_regimes')->where('legal_entity_id', $entity->id)->update(['valid_from' => '2020-01-01']);
        $this->entity = $entity->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->profile2 = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->unit2 = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->unit3 = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    $options = movCall($this->admin, 'get', '/employment-options')->assertOk();
    $contract = collect($options->json('data.contract_types'))->firstWhere('code', 'indeterminado')['id'];
    $hire = movCall($this->admin, 'post', '/employments', [
        'person' => ['first_names' => 'Lucía', 'paternal_surname' => 'Huamán', 'maternal_surname' => 'Ccori',
            'document_type' => 'dni', 'document_number' => (string) random_int(10000000, 89999999)],
        'legal_entity_id' => $this->entity, 'hire_date' => '2025-03-10', 'job_profile_id' => $this->profile,
        'org_unit_id' => $this->unit, 'site_id' => $this->site, 'contract_type_id' => $contract,
    ])->assertCreated();
    $this->employment = (string) $hire->json('data.id');
    $this->path = "/employments/{$this->employment}/personnel-actions";
});

afterEach(function (): void {
    TenancyScope::clear();
    Carbon::setTestNow();
});

function movUser(string $tenant, string $role): User
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
function movCall(User $user, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(PermissionResolver::class)->forget();
    app(ModuleGate::class)->forget();
    app(DataScope::class)->forget();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/** Tipos de movimiento que pasan por aprobación en la organización de prueba. */
function movApprovalKinds(array $kinds): void
{
    TenancyScope::runAs(MOV_TENANT, fn () => app(ParameterResolver::class)->set('movimientos.tipos_con_aprobacion', $kinds, 'organization', null, null, 'prueba'));
}

/** Flujo publicado para movimientos de personal: un nivel, aprueba la administradora. */
function movFlow(User $admin): void
{
    $flow = movCall($admin, 'POST', '/approval-flows', ['name' => 'Movimientos', 'subject_type' => PersonnelActions::SUBJECT_TYPE,
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $admin->id]]]]]]);
    $flow->assertCreated();
    movCall($admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
}

function movAssignments(string $employment): array
{
    return TenancyScope::runAs(MOV_TENANT, fn () => DB::table('employment_assignments')->where('employment_id', $employment)->orderBy('valid_from')->get()->all());
}

// ── HIS-4 · Programados a futuro ────────────────────────────────────────────────────────────────────

test('HIS-4: un movimiento futuro queda programado, no cambia nada hasta su fecha y se edita o anula antes', function (): void {
    movApprovalKinds([]);
    $date = now()->addDays(20)->toDateString();
    $r = movCall($this->rrhh, 'post', $this->path, ['effective_on' => $date, 'reason' => 'Traslado a operaciones', 'changes' => ['org_unit_id' => $this->unit2]]);
    $r->assertCreated();
    $this->assertMatchesOpenApi($r, 'post', '/employments/{employment_id}/personnel-actions', 201);
    $id = (string) $r->json('data.id');
    expect($r->json('data.status'))->toBe('scheduled')->and($r->json('data.kind'))->toBe('transfer')
        ->and($r->json('data.after.org_unit_id.value'))->toBe($this->unit2)
        ->and($r->json('data.allowed_actions'))->toBe(['edit', 'cancel']);
    // RN-HIS-04: hasta su fecha no toca la asignación.
    expect(movAssignments($this->employment))->toHaveCount(1);

    $list = movCall($this->rrhh, 'get', '/personnel-actions')->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/personnel-actions', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toContain($id)
        ->and($list->json('data.0.person_name'))->toBe('Lucía Huamán Ccori');
    movCall($this->rrhh, 'get', '/personnel-actions?filter[status]=nada')->assertStatus(400);

    // Mientras haya uno programado, no se registra otro (409).
    $dup = movCall($this->rrhh, 'post', $this->path, ['effective_on' => now()->addDays(40)->toDateString(), 'changes' => ['site_id' => $this->site, 'org_unit_id' => $this->unit3]]);
    $dup->assertStatus(409);
    $this->assertMatchesOpenApi($dup, 'post', '/employments/{employment_id}/personnel-actions', 409);

    $later = now()->addDays(30)->toDateString();
    $edit = movCall($this->rrhh, 'patch', "/personnel-actions/{$id}", ['effective_on' => $later, 'changes' => ['org_unit_id' => $this->unit3]]);
    $edit->assertOk();
    $this->assertMatchesOpenApi($edit, 'patch', '/personnel-actions/{id}', 200);
    expect($edit->json('data.effective_on'))->toBe($later)->and($edit->json('data.changes.org_unit_id'))->toBe($this->unit3);
    $past = movCall($this->rrhh, 'patch', "/personnel-actions/{$id}", ['effective_on' => now()->subDay()->toDateString()]);
    $past->assertStatus(422);
    $this->assertMatchesOpenApi($past, 'patch', '/personnel-actions/{id}', 422);

    $show = movCall($this->rrhh, 'get', "/personnel-actions/{$id}")->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/personnel-actions/{id}', 200);

    $cancel = movCall($this->rrhh, 'post', "/personnel-actions/{$id}/cancel", ['reason' => 'Se mantiene en su unidad']);
    $cancel->assertOk();
    $this->assertMatchesOpenApi($cancel, 'post', '/personnel-actions/{id}/cancel', 200);
    expect($cancel->json('data.status'))->toBe('cancelled')->and($cancel->json('data.decision_reason'))->toBe('Se mantiene en su unidad');
    $again = movCall($this->rrhh, 'post', "/personnel-actions/{$id}/cancel", ['reason' => 'Otra vez']);
    $again->assertStatus(409);
    $this->assertMatchesOpenApi($again, 'post', '/personnel-actions/{id}/cancel', 409);
    movCall($this->rrhh, 'post', "/personnel-actions/{$id}/cancel", [])->assertStatus(422);

    TenancyScope::runAs(MOV_TENANT, function () use ($id): void {
        expect(DB::table('personnel_actions')->where('id', $id)->whereNull('deleted_at')->exists())->toBeTrue()
            ->and(DB::table('outbox_events')->where('aggregate_id', $id)->where('event_type', 'personnel_action.cancelled.v1')->exists())->toBeTrue()
            ->and(DB::table('audit_events')->where('entity_id', $id)->where('action', 'personnel_action.updated')->exists())->toBeTrue();
    });
});

test('HIS-4: el día de su fecha el movimiento programado se aplica solo y emite el traslado', function (): void {
    movApprovalKinds([]);
    $date = now()->addDays(10)->toDateString();
    $id = (string) movCall($this->rrhh, 'post', $this->path, ['effective_on' => $date, 'changes' => ['org_unit_id' => $this->unit2]])->assertCreated()->json('data.id');

    Carbon::setTestNow(Carbon::parse($date)->setTime(0, 30));
    $this->artisan('personnel-actions:apply-due')->assertSuccessful();

    $rows = movAssignments($this->employment);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->valid_to)->toBe(Carbon::parse($date)->subDay()->toDateString())
        ->and($rows[1]->org_unit_id)->toBe($this->unit2)
        ->and($rows[1]->personnel_action_id)->toBe($id);
    TenancyScope::runAs(MOV_TENANT, function () use ($id): void {
        expect(DB::table('personnel_actions')->where('id', $id)->value('status'))->toBe('applied')
            ->and(DB::table('outbox_events')->where('aggregate_id', $this->employment)->where('event_type', 'employee.transferred.v1')->exists())->toBeTrue()
            ->and(DB::table('outbox_events')->where('aggregate_id', $this->employment)->where('event_type', 'employee.updated.v1')->exists())->toBeTrue();
    });
});

// ── HIS-2 · Aprobación ──────────────────────────────────────────────────────────────────────────────

test('HIS-2: una promoción pasa por el flujo y, aprobada, se aplica identificada como promoción', function (): void {
    movFlow($this->admin);
    $r = movCall($this->rrhh, 'post', $this->path, ['effective_on' => now()->toDateString(), 'reason' => 'Ascenso a supervisora',
        'changes' => ['job_profile_id' => $this->profile2]]);
    $r->assertCreated();
    expect($r->json('data.status'))->toBe('pending_approval')->and($r->json('data.kind'))->toBe('promotion')
        ->and($r->json('data.approval.pending_approvers'))->toHaveCount(1);
    expect(movAssignments($this->employment))->toHaveCount(1);

    movCall($this->admin, 'POST', '/approval-requests/'.$r->json('data.approval_request_id').'/approve')->assertOk();

    $show = movCall($this->rrhh, 'get', '/personnel-actions/'.$r->json('data.id'))->assertOk();
    expect($show->json('data.status'))->toBe('applied')->and($show->json('data.decided_at'))->not->toBeNull();
    $rows = movAssignments($this->employment);
    expect($rows)->toHaveCount(2)->and($rows[1]->job_profile_id)->toBe($this->profile2);
    TenancyScope::runAs(MOV_TENANT, function (): void {
        expect(DB::table('outbox_events')->where('aggregate_id', $this->employment)->where('event_type', 'employee.promoted.v1')->exists())->toBeTrue();
    });
});

test('HIS-2: lo que propone un jefe siempre pasa por aprobación; rechazado no cambia nada', function (): void {
    movApprovalKinds([]);
    movFlow($this->admin);
    $boss = IdentityFixtures::userWithPermissions(MOV_TENANT, ['people.assignments.view', 'people.assignments.propose']);
    $r = movCall($boss, 'post', "{$this->path}/propose", ['effective_on' => now()->addDays(5)->toDateString(), 'changes' => ['org_unit_id' => $this->unit2]]);
    $this->assertMatchesOpenApi($r, 'post', '/employments/{employment_id}/personnel-actions/propose', 201);
    $r->assertCreated();
    expect($r->json('data.status'))->toBe('pending_approval');

    movCall($this->admin, 'POST', '/approval-requests/'.$r->json('data.approval_request_id').'/reject', ['comment' => 'No hay plaza'])->assertOk();
    $show = movCall($this->rrhh, 'get', '/personnel-actions/'.$r->json('data.id'))->assertOk();
    expect($show->json('data.status'))->toBe('rejected');
    expect(movAssignments($this->employment))->toHaveCount(1);

    // Sin flujo publicado, lo que requiere aprobación no se registra.
    TenancyScope::runAs(MOV_TENANT, fn () => DB::table('workflow_definitions')->where('subject_type', PersonnelActions::SUBJECT_TYPE)->update(['status' => 'inactive']));
    movCall($boss, 'post', "{$this->path}/propose", ['effective_on' => now()->addDays(5)->toDateString(), 'changes' => ['org_unit_id' => $this->unit2]])->assertStatus(422);
    // Quien solo propone no registra directamente.
    movCall($boss, 'post', $this->path, ['effective_on' => now()->addDays(5)->toDateString(), 'changes' => ['org_unit_id' => $this->unit2]])->assertForbidden();
});

// ── HIS-4 · Retroactivos y corrección ───────────────────────────────────────────────────────────────

test('HIS-4: un cambio con fecha pasada emite el rango afectado; corregirlo deja el original reemplazado', function (): void {
    movApprovalKinds([]);
    $r = movCall($this->rrhh, 'post', $this->path, ['effective_on' => '2026-02-01', 'changes' => ['org_unit_id' => $this->unit2]])->assertCreated();
    $id = (string) $r->json('data.id');
    expect($r->json('data.status'))->toBe('applied')->and($r->json('data.is_retroactive'))->toBeTrue()
        ->and($r->json('data.affected_from'))->toBe('2026-02-01')
        ->and($r->json('data.affected_to'))->toBe(now()->subDay()->toDateString())
        ->and($r->json('data.allowed_actions'))->toBe(['correct']);
    TenancyScope::runAs(MOV_TENANT, function () use ($id): void {
        $event = DB::table('outbox_events')->where('aggregate_id', $this->employment)->where('event_type', 'employment.retroactive_change.v1')->first();
        expect($event)->not->toBeNull();
        $payload = json_decode((string) $event->payload, true);
        expect($payload['personnel_action_id'] ?? null)->toBe($id)->and($payload['affected_from'] ?? null)->toBe('2026-02-01');
    });

    $fix = movCall($this->rrhh, 'post', "/personnel-actions/{$id}/correct", ['reason' => 'La unidad era otra', 'changes' => ['org_unit_id' => $this->unit3]]);
    $fix->assertOk();
    $this->assertMatchesOpenApi($fix, 'post', '/personnel-actions/{id}/correct', 200);
    expect($fix->json('data.kind'))->toBe('data_correction')->and($fix->json('data.status'))->toBe('applied')
        ->and($fix->json('data.replaces_personnel_action_id'))->toBe($id)
        ->and($fix->json('data.before.org_unit_id.value'))->toBe($this->unit2)
        ->and($fix->json('data.after.org_unit_id.value'))->toBe($this->unit3);

    $rows = movAssignments($this->employment);
    expect($rows)->toHaveCount(2)->and($rows[1]->org_unit_id)->toBe($this->unit3)->and($rows[1]->valid_from)->toBe('2026-02-01');
    // RN-HIS-06: el original sigue en la historia, reemplazado y enlazado, con su valor original.
    $history = movCall($this->rrhh, 'get', $this->path)->assertOk();
    $this->assertMatchesOpenApi($history, 'get', '/employments/{employment_id}/personnel-actions', 200);
    $original = collect($history->json('data'))->firstWhere('id', $id);
    expect($original['status'])->toBe('replaced')->and($original['replaced_by_personnel_action_id'])->toBe($fix->json('data.id'))
        ->and($original['after']['org_unit_id']['value'])->toBe($this->unit2);
    movCall($this->rrhh, 'post', "/personnel-actions/{$id}/correct", ['reason' => 'Otra vez', 'changes' => ['org_unit_id' => $this->unit]])->assertStatus(409);
    movCall($this->rrhh, 'post', '/personnel-actions/'.$fix->json('data.id').'/correct', ['reason' => 'x'])->assertStatus(422);
});

test('HIS-4 · RN-HIS-05: una fecha en un periodo de planilla cerrado exige permiso de corrección', function (): void {
    movApprovalKinds([]);
    TenancyScope::runAs(MOV_TENANT, function (): void {
        $group = DB::table('payroll_groups')->insertGetId(['tenant_id' => MOV_TENANT, 'code' => 'MENS', 'name' => 'Mensual', 'legal_entity_id' => $this->entity, 'currency' => 'PEN']);
        DB::table('payroll_periods')->insert(['tenant_id' => MOV_TENANT, 'payroll_group_id' => $group, 'period_year' => 2026, 'period_number' => 3,
            'starts_on' => '2026-03-01', 'ends_on' => '2026-03-31', 'status' => 'closed']);
    });
    $clerk = IdentityFixtures::userWithPermissions(MOV_TENANT, ['people.assignments.view', 'people.assignments.create']);
    $closed = movCall($clerk, 'post', $this->path, ['effective_on' => '2026-03-15', 'changes' => ['org_unit_id' => $this->unit2]]);
    $closed->assertStatus(422)->assertJsonPath('error.code', 'closed_period');
    $this->assertMatchesOpenApi($closed, 'post', '/employments/{employment_id}/personnel-actions', 422);

    $ok = movCall($this->rrhh, 'post', $this->path, ['effective_on' => '2026-03-15', 'changes' => ['org_unit_id' => $this->unit2]])->assertCreated();
    expect($ok->json('data.status'))->toBe('applied')->and($ok->json('data.is_retroactive'))->toBeTrue();
});

// ── Permisos, aislamiento y estados ─────────────────────────────────────────────────────────────────

test('HIS-2: sin permiso 403, de otra organización 404', function (): void {
    $viewer = IdentityFixtures::userWithPermissions(MOV_TENANT, ['people.assignments.view']);
    $denied = movCall($viewer, 'post', $this->path, ['effective_on' => now()->toDateString(), 'changes' => ['org_unit_id' => $this->unit2]]);
    $denied->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/employments/{employment_id}/personnel-actions', 403);
    movCall($viewer, 'post', '/personnel-actions/'.Str::uuid().'/correct', ['reason' => 'x'])->assertForbidden();

    movApprovalKinds([]);
    $id = (string) movCall($this->rrhh, 'post', $this->path, ['effective_on' => now()->addDays(9)->toDateString(), 'changes' => ['org_unit_id' => $this->unit2]])->json('data.id');
    $other = movUser(MOV_OTHER, 'admin_empresa');
    movCall($other, 'get', "/personnel-actions/{$id}")->assertNotFound();
    movCall($other, 'get', $this->path)->assertNotFound();
    movCall($other, 'post', "/personnel-actions/{$id}/cancel", ['reason' => 'ajeno'])->assertNotFound();
    expect(collect(movCall($other, 'get', '/personnel-actions')->assertOk()->json('data'))->pluck('id')->all())->not->toContain($id);

    IdentityFixtures::disableModule(MOV_TENANT, 'core');
    movCall($this->rrhh, 'get', '/personnel-actions')->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
});

test('HIS-4: un movimiento programado después de la fecha de cese bloquea el cese', function (): void {
    movApprovalKinds([]);
    movCall($this->rrhh, 'post', $this->path, ['effective_on' => now()->addDays(30)->toDateString(), 'changes' => ['org_unit_id' => $this->unit2]])->assertCreated();
    $reasons = movCall($this->admin, 'get', '/termination-reasons')->assertOk();
    $reason = collect($reasons->json('data'))->first()['id'];
    $r = movCall($this->admin, 'post', "/employments/{$this->employment}/terminate", ['termination_reason_id' => $reason, 'termination_date' => now()->addDays(10)->toDateString()]);
    $r->assertStatus(409)->assertJsonPath('error.code', 'future_changes');
    expect($r->json('error.meta.tables'))->toContain('personnel_actions');
});

test('matriz del movimiento de personal: cada estado admite solo sus acciones', function (): void {
    $machine = new PersonnelActionStateMachine;
    $expected = [
        'draft' => ['apply' => 'applied', 'schedule' => 'scheduled', 'cancel' => 'cancelled'],
        'pending_approval' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
        'approved' => ['apply' => 'applied', 'schedule' => 'scheduled', 'cancel' => 'cancelled'],
        'scheduled' => ['apply' => 'applied', 'edit' => 'scheduled', 'cancel' => 'cancelled'],
        'applied' => ['correct' => 'replaced'],
        'rejected' => [], 'cancelled' => [], 'replaced' => [],
    ];
    foreach ($expected as $state => $allowed) {
        foreach (['approve', 'reject', 'schedule', 'apply', 'edit', 'cancel', 'correct'] as $action) {
            if (isset($allowed[$action])) {
                expect($machine->getNextState($state, $action))->toBe($allowed[$action]);
            } else {
                expect(fn () => $machine->getNextState($state, $action))->toThrow(InvalidTransitionException::class);
            }
        }
    }
});
