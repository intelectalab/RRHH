<?php

/**
 * POS-3 · Solicitud y aprobación de nuevas posiciones (ME-04): borrador → en aprobación por el flujo
 * configurado → aprobada (crea sus posiciones en «aprobada», trazadas con la solicitud) o rechazada
 * (exige motivo y el motor avisa al solicitante). RN-POS-05: si supera el presupuesto disponible de la
 * unidad, la solicitud se marca y el flujo recibe over_budget para exigir un nivel adicional.
 * POS-4 · Presupuesto de dotación por unidad con vigencia, tablero de aprobadas, ocupadas, vacantes y
 * congeladas, costo proyectado sensible (RN-POS-07) y desviación explicada por posición; aprobar una
 * plaza fuera de presupuesto exige una excepción con motivo.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Database\Factories\EmploymentCostDistributionFactory;
use App\Modules\Organization\Domain\PositionRequestStateMachine;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;

uses(DatabaseTransactions::class);

const PRQ_TENANT = 'aaaaaaaa-0b03-4000-8000-00000000000a';
const PRQ_OTHER = 'bbbbbbbb-0b03-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([PRQ_TENANT => 'prq-org', PRQ_OTHER => 'prq-otra'] as $id => $slug) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => 'Grupo '.$slug, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
        TenancyScope::runAs($id, fn () => IdentityFixtures::enableAllModules());
    }
    $this->admin = prqUser(PRQ_TENANT, 'admin_empresa', 'Ana Admin');
    $this->hr = prqUser(PRQ_TENANT, 'rrhh', 'Rita RRHH');
    TenancyScope::runAs(PRQ_TENANT, function (): void {
        $this->entity = LegalEntity::factory()->create()->id;
        $this->profile = JobProfile::factory()->create()->id;
        $this->unit = OrgUnit::factory()->create(['legal_entity_id' => $this->entity])->id;
        $this->site = Site::factory()->create(['legal_entity_id' => $this->entity])->id;
    });
    // Flujo publicado para solicitudes de posición: un nivel, aprueba la administradora.
    $flow = prqCall($this->admin, 'POST', '/approval-flows', ['name' => 'Posiciones', 'subject_type' => 'position_request',
        'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->admin->id]]]]]]);
    $flow->assertCreated();
    prqCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
});

afterEach(fn () => TenancyScope::clear());

function prqUser(string $tenant, string $role, string $name = 'Usuario'): User
{
    return TenancyScope::runAs($tenant, function () use ($role, $name): User {
        $u = User::factory()->create(['display_name' => $name]);
        $u->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $role)->value('id')]);

        return $u;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function prqCall(User $user, string $method, string $path, array $body = [], array $headers = []): TestResponse
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
function prqBody(array $extra = []): array
{
    return $extra + [
        'legal_entity_id' => test()->entity,
        'org_unit_id' => test()->unit,
        'job_profile_id' => test()->profile,
        'title' => 'Analista de costos',
        'quantity' => 1,
        'justification' => 'Nueva línea de negocio en el área',
        'needed_by' => '2026-12-01',
    ];
}

/** Solicitud enviada por RRHH; devuelve [id de la solicitud, id de la aprobación]. */
function prqSubmitted(array $extra = []): array
{
    $created = prqCall(test()->hr, 'POST', '/position-requests', prqBody($extra));
    $created->assertCreated();
    $sent = prqCall(test()->hr, 'POST', '/position-requests/'.$created->json('data.id').'/submit');
    $sent->assertOk();

    return [(string) $created->json('data.id'), (string) $sent->json('data.approval_request_id')];
}

/** Posición aprobada en la unidad de prueba (con banda opcional). */
function prqPosition(string $status = 'approved', ?string $band = null, string $from = '2026-01-01'): string
{
    $res = prqCall(test()->admin, 'POST', '/positions', [
        'code' => 'h-'.Str::lower(Str::random(6)), 'title' => 'Plaza', 'job_profile_id' => test()->profile,
        'legal_entity_id' => test()->entity, 'org_unit_id' => test()->unit, 'valid_from' => $from, 'salary_band_id' => $band,
    ]);
    $res->assertCreated();
    $id = (string) $res->json('data.id');
    if ($status !== 'proposed') {
        prqCall(test()->admin, 'POST', "/positions/{$id}/approve", ['reason' => 'Plaza de prueba'])->assertOk();
    }
    if (in_array($status, ['frozen', 'open'], true)) {
        TenancyScope::runAs(PRQ_TENANT, fn () => DB::table('positions')->where('id', $id)->update(['status' => $status]));
    }

    return $id;
}

function prqBand(string $min, string $max, ?string $mid = null): string
{
    return TenancyScope::runAs(PRQ_TENANT, function () use ($min, $max, $mid): string {
        $id = (string) Str::uuid();
        DB::table('salary_bands')->insert(['id' => $id, 'tenant_id' => PRQ_TENANT, 'name' => 'Banda '.$min, 'min_amount' => $min,
            'mid_amount' => $mid, 'max_amount' => $max, 'currency' => 'PEN', 'valid_from' => '2026-01-01']);

        return $id;
    });
}

// ---------------------------------------------------------------- POS-3 · Solicitudes

test('la solicitud aprobada por el flujo crea sus posiciones en «aprobada», trazadas desde la solicitud', function (): void {
    $created = prqCall($this->hr, 'POST', '/position-requests', prqBody(['quantity' => 2, 'estimated_cost' => ['amount' => '4500.00', 'currency' => 'PEN']]));
    $created->assertCreated();
    $this->assertMatchesOpenApi($created, 'post', '/position-requests', 201);
    expect($created->json('data'))->toMatchArray(['status' => 'draft', 'quantity' => 2, 'allowed_actions' => ['submit', 'cancel']]);
    $id = (string) $created->json('data.id');

    $sent = prqCall($this->hr, 'POST', "/position-requests/{$id}/submit");
    $sent->assertOk();
    $this->assertMatchesOpenApi($sent, 'post', '/position-requests/{id}/submit', 200);
    expect($sent->json('data.status'))->toBe('submitted')
        ->and($sent->json('data.over_budget'))->toBeFalse()
        ->and($sent->json('data.approval.pending_approvers'))->toBe(['Ana Admin']);
    $approval = (string) $sent->json('data.approval_request_id');
    TenancyScope::runAs(PRQ_TENANT, function () use ($id): void {
        expect(DB::table('outbox_events')->where('aggregate_id', $id)->where('event_type', 'position.requested.v1')->exists())->toBeTrue();
    });

    prqCall($this->admin, 'POST', "/approval-requests/{$approval}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $show = prqCall($this->hr, 'GET', "/position-requests/{$id}");
    $show->assertOk();
    $this->assertMatchesOpenApi($show, 'get', '/position-requests/{id}', 200);
    expect($show->json('data.status'))->toBe('approved')
        ->and($show->json('data.decided_at'))->not->toBeNull()
        ->and($show->json('data.positions'))->toHaveCount(2);
    foreach ($show->json('data.positions') as $p) {
        $position = prqCall($this->admin, 'GET', '/positions/'.$p['id']);
        expect($position->json('data'))->toMatchArray([
            'status' => 'approved', 'position_request_id' => $id, 'org_unit_id' => $this->unit, 'title' => 'Analista de costos',
            'valid_from' => '2026-12-01', 'budget' => ['amount' => '4500.00', 'currency' => 'PEN'],
        ]);
    }
    TenancyScope::runAs(PRQ_TENANT, function () use ($id): void {
        expect(DB::table('audit_events')->where('action', 'position_request.approved')->where('entity_id', $id)->exists())->toBeTrue()
            ->and(DB::table('position_status_events')->whereIn('position_id', DB::table('positions')->where('position_request_id', $id)->select('id'))->where('to_status', 'approved')->count())->toBe(2);
    });
});

test('el rechazo exige motivo, lo guarda en la solicitud y avisa al solicitante', function (): void {
    [$id, $approval] = prqSubmitted();

    $noReason = prqCall($this->admin, 'POST', "/approval-requests/{$approval}/reject");
    $noReason->assertStatus(422);

    prqCall($this->admin, 'POST', "/approval-requests/{$approval}/reject", ['comment' => 'No hay presupuesto este año'])->assertOk();
    $show = prqCall($this->hr, 'GET', "/position-requests/{$id}");
    expect($show->json('data'))->toMatchArray(['status' => 'rejected', 'decision_reason' => 'No hay presupuesto este año'])
        ->and($show->json('data.positions'))->toBe([]);
    TenancyScope::runAs(PRQ_TENANT, function () use ($approval, $id): void {
        // El motor publica el rechazo con el motivo; NotifyWorkflowParticipants avisa a quien la inició (el solicitante).
        $event = DB::table('outbox_events')->where('aggregate_id', $approval)->where('event_type', 'approval.rejected.v1')->first();
        expect($event)->not->toBeNull();
        $payload = json_decode((string) $event->payload, true);
        expect($payload['subject_id'])->toBe($id)->and($payload['comment'])->toBe('No hay presupuesto este año')
            ->and(DB::table('workflow_instances')->where('id', $approval)->value('started_by_user_id'))->toBe($this->hr->id);
    });
});

test('cancelar en aprobación anula los pasos pendientes; las transiciones inválidas responden 409', function (): void {
    [$id, $approval] = prqSubmitted();

    $again = prqCall($this->hr, 'POST', "/position-requests/{$id}/submit");
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/position-requests/{id}/submit', 409);
    prqCall($this->hr, 'PATCH', "/position-requests/{$id}", ['title' => 'Otro nombre'])->assertStatus(409);

    $cancel = prqCall($this->hr, 'POST', "/position-requests/{$id}/cancel", ['reason' => 'Se reorganiza el área']);
    $cancel->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->assertMatchesOpenApi($cancel, 'post', '/position-requests/{id}/cancel', 200);
    TenancyScope::runAs(PRQ_TENANT, function () use ($approval): void {
        expect(DB::table('workflow_instances')->where('id', $approval)->value('status'))->toBe('cancelled')
            ->and(DB::table('workflow_tasks')->where('workflow_instance_id', $approval)->where('status', 'pending')->count())->toBe(0);
    });
    prqCall($this->hr, 'POST', "/position-requests/{$id}/cancel")->assertStatus(409);
});

test('matriz de ME-04: cada estado admite solo sus acciones', function (): void {
    $machine = new PositionRequestStateMachine;
    $expected = [
        'draft' => ['submit' => 'submitted', 'cancel' => 'cancelled'],
        'submitted' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
        'approved' => ['fulfill' => 'fulfilled'],
        'rejected' => [], 'cancelled' => [], 'fulfilled' => [],
    ];
    foreach ($expected as $state => $allowed) {
        foreach (['submit', 'cancel', 'approve', 'reject', 'fulfill'] as $action) {
            if (isset($allowed[$action])) {
                expect($machine->getNextState($state, $action))->toBe($allowed[$action]);
            } else {
                expect(fn () => $machine->getNextState($state, $action))->toThrow(InvalidTransitionException::class);
            }
        }
    }
    expect($machine->manualActions('submitted'))->toBe(['cancel']);
});

test('sin flujo publicado no se envía; datos inválidos responden 422', function (): void {
    TenancyScope::runAs(PRQ_TENANT, fn () => DB::table('workflow_definitions')->where('subject_type', 'position_request')->update(['status' => 'inactive']));
    $created = prqCall($this->hr, 'POST', '/position-requests', prqBody());
    $sent = prqCall($this->hr, 'POST', '/position-requests/'.$created->json('data.id').'/submit');
    $sent->assertStatus(422);
    $this->assertMatchesOpenApi($sent, 'post', '/position-requests/{id}/submit', 422);
    expect(prqCall($this->hr, 'GET', '/position-requests/'.$created->json('data.id'))->json('data.status'))->toBe('draft');

    $bad = prqCall($this->hr, 'POST', '/position-requests', ['title' => 'X']);
    $bad->assertStatus(422);
    $this->assertMatchesOpenApi($bad, 'post', '/position-requests', 422);
    $otherUnit = TenancyScope::runAs(PRQ_TENANT, fn () => OrgUnit::factory()->create(['legal_entity_id' => LegalEntity::factory()->create()->id])->id);
    prqCall($this->hr, 'POST', '/position-requests', prqBody(['org_unit_id' => $otherUnit]))->assertStatus(422)->assertJsonPath('error.details.0.field', 'org_unit_id');
});

test('quien solicita ve solo las suyas; sin permiso 403; con el módulo apagado 403 module_not_enabled', function (): void {
    [$hrRequest] = prqSubmitted();
    // Un manager con relación laboral en la empresa solicita una plaza y solo ve la suya.
    $manager = prqUser(PRQ_TENANT, 'manager', 'Mario Manager');
    TenancyScope::runAs(PRQ_TENANT, function () use ($manager): void {
        $employment = EmploymentCostDistributionFactory::employment($this->entity);
        DB::table('users')->where('id', $manager->id)->update(['person_id' => DB::table('employments')->where('id', $employment)->value('person_id')]);
    });
    $mine = prqCall($manager, 'POST', '/position-requests', prqBody(['estimated_cost' => ['amount' => '3000.00', 'currency' => 'PEN']]));
    $mine->assertCreated();
    // El solicitante ve el costo que registró aunque no tenga permiso de costos.
    expect($mine->json('data.estimated_cost'))->toBe(['amount' => '3000.00', 'currency' => 'PEN']);

    $list = prqCall($manager, 'GET', '/position-requests');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/position-requests', 200);
    expect(array_column($list->json('data'), 'id'))->toBe([$mine->json('data.id')]);
    prqCall($manager, 'GET', "/position-requests/{$hrRequest}")->assertNotFound();
    expect(count(prqCall($this->hr, 'GET', '/position-requests')->json('data')))->toBe(2);

    // Un auditor ve todas pero no solicita, y sin permiso de costos no recibe el costo estimado.
    $auditor = prqUser(PRQ_TENANT, 'auditor');
    $denied = prqCall($auditor, 'POST', '/position-requests', prqBody());
    $denied->assertForbidden();
    $this->assertMatchesOpenApi($denied, 'post', '/position-requests', 403);
    $seen = prqCall($auditor, 'GET', '/position-requests/'.$mine->json('data.id'));
    // El auditor no aprueba posiciones: solo ve las propias (ninguna).
    $seen->assertNotFound();
    $empleado = prqUser(PRQ_TENANT, 'empleado');
    prqCall($empleado, 'GET', '/position-requests')->assertForbidden();

    IdentityFixtures::disableModule(PRQ_TENANT, 'core');
    $off = prqCall($this->hr, 'GET', '/position-requests');
    $off->assertForbidden()->assertJsonPath('error.code', 'module_not_enabled');
    $this->assertMatchesOpenApi($off, 'get', '/position-requests', 403);
});

test('RN-POS-05: una solicitud que supera el presupuesto disponible se marca y el flujo lo recibe', function (): void {
    prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 1, 'valid_from' => '2026-01-01'])->assertCreated();
    prqPosition('approved');

    [$id, $approval] = prqSubmitted();
    $show = prqCall($this->hr, 'GET', "/position-requests/{$id}");
    expect($show->json('data.over_budget'))->toBeTrue()
        ->and($show->json('data.budget'))->toMatchArray(['has_budget' => true, 'budgeted_positions' => 1, 'committed_positions' => 1, 'available_positions' => 0]);
    TenancyScope::runAs(PRQ_TENANT, function () use ($approval): void {
        $context = json_decode((string) DB::table('workflow_instances')->where('id', $approval)->value('context'), true);
        expect($context['over_budget'])->toBeTrue()->and($context['org_unit_id'])->toBe($this->unit);
    });

    // Aprobada por el flujo, la plaza queda creada con la excepción en su historial.
    prqCall($this->admin, 'POST', "/approval-requests/{$approval}/approve")->assertOk();
    TenancyScope::runAs(PRQ_TENANT, function () use ($id): void {
        $reason = DB::table('position_status_events')->whereIn('position_id', DB::table('positions')->where('position_request_id', $id)->select('id'))->value('reason');
        expect($reason)->toContain('fuera de presupuesto');
    });
});

// ---------------------------------------------------------------- POS-4 · Presupuesto y tablero

test('el presupuesto se registra por unidad con vigencia: uno nuevo cierra el vigente', function (): void {
    $first = prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 3,
        'monthly_cost' => ['amount' => '15000.00', 'currency' => 'PEN'], 'valid_from' => '2026-01-01']);
    $first->assertCreated();
    $this->assertMatchesOpenApi($first, 'post', '/headcount-budgets', 201);
    expect($first->json('data'))->toMatchArray(['budgeted_positions' => 3, 'legal_entity_id' => $this->entity, 'monthly_cost' => ['amount' => '15000.00', 'currency' => 'PEN']]);

    $second = prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 5, 'valid_from' => '2026-07-01']);
    $second->assertCreated();
    $old = prqCall($this->hr, 'GET', '/headcount-budgets/'.$first->json('data.id'));
    $this->assertMatchesOpenApi($old, 'get', '/headcount-budgets/{id}', 200);
    expect($old->json('data.valid_to'))->toBe('2026-06-30');

    $sameDay = prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 6, 'valid_from' => '2026-07-01']);
    $sameDay->assertStatus(409);
    $this->assertMatchesOpenApi($sameDay, 'post', '/headcount-budgets', 409);

    $asOf = prqCall($this->hr, 'GET', '/headcount-budgets?org_unit_id='.$this->unit.'&as_of=2026-03-15');
    $asOf->assertOk();
    $this->assertMatchesOpenApi($asOf, 'get', '/headcount-budgets', 200);
    expect(array_column($asOf->json('data'), 'budgeted_positions'))->toBe([3]);

    $patch = prqCall($this->hr, 'PATCH', '/headcount-budgets/'.$second->json('data.id'), ['budgeted_positions' => 4, 'notes' => 'Ajuste']);
    $patch->assertOk();
    $this->assertMatchesOpenApi($patch, 'patch', '/headcount-budgets/{id}', 200);
    expect($patch->json('data.budgeted_positions'))->toBe(4);
    TenancyScope::runAs(PRQ_TENANT, function (): void {
        expect(DB::table('audit_events')->where('action', 'headcount_budget.closed')->exists())->toBeTrue();
    });

    // Sin permiso de costos no se registra el costo; sin permiso del plan, 403.
    $recruiter = prqUser(PRQ_TENANT, 'recruiter');
    prqCall($recruiter, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 1, 'valid_from' => '2027-01-01'])->assertForbidden();
    prqCall($this->hr, 'PATCH', '/headcount-budgets/'.$second->json('data.id'), ['budgeted_positions' => -1])->assertStatus(422);
});

test('el tablero muestra aprobadas, ocupadas, vacantes y congeladas por unidad frente al presupuesto', function (): void {
    prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 3,
        'monthly_cost' => ['amount' => '9000.00', 'currency' => 'PEN'], 'valid_from' => '2026-01-01'])->assertCreated();
    $band = prqBand('2000.00', '4000.00');
    $filled = prqPosition('approved', $band);
    prqPosition('approved', $band);
    prqPosition('frozen', $band);
    prqPosition('approved', $band);
    prqPosition('proposed');
    TenancyScope::runAs(PRQ_TENANT, function () use ($filled): void {
        $employment = EmploymentCostDistributionFactory::employment($this->entity);
        DB::table('employment_assignments')->insert(['tenant_id' => PRQ_TENANT, 'employment_id' => $employment, 'job_profile_id' => $this->profile,
            'position_id' => $filled, 'org_unit_id' => $this->unit, 'site_id' => $this->site, 'valid_from' => '2026-01-01', 'reason' => 'Ingreso']);
        DB::table('employment_compensations')->insert(['tenant_id' => PRQ_TENANT, 'employment_id' => $employment, 'base_salary' => '3500.00',
            'currency' => 'PEN', 'valid_from' => '2026-01-01']);
        app(PositionOccupancy::class)->sync($filled);
        app(ParameterResolver::class)->set('posiciones.factor_carga_pct', 10, 'organization', null, '2026-01-01', 'Estimación de cargas');
    });

    $board = prqCall($this->admin, 'GET', '/headcount?as_of=2026-11-30');
    $board->assertOk();
    $this->assertMatchesOpenApi($board, 'get', '/headcount', 200);
    $row = collect($board->json('data.units'))->firstWhere('org_unit_id', $this->unit);
    expect($row['counts'])->toBe(['proposed' => 1, 'approved' => 4, 'filled' => 1, 'vacant' => 2, 'recruiting' => 0, 'frozen' => 1])
        ->and($row['budgeted_positions'])->toBe(3)
        ->and($row['deviation_positions'])->toBe(1)
        // 3500 del ocupante + 3 plazas sin ocupante al punto medio 3000 = 12 500, más 10 % de cargas = 13 750.
        ->and($row['projected_cost'])->toBe(['amount' => '13750.00', 'currency' => 'PEN'])
        ->and($row['deviation_cost'])->toBe(['amount' => '4750.00', 'currency' => 'PEN']);
    TenancyScope::runAs(PRQ_TENANT, function (): void {
        expect(DB::table('data_access_logs')->where('user_id', $this->admin->id)->exists())->toBeTrue();
    });

    // El costo proyectado exige permiso para ver sueldos: un reclutador ve las cantidades, no los montos.
    $recruiter = prqUser(PRQ_TENANT, 'recruiter');
    $plain = prqCall($recruiter, 'GET', '/headcount?as_of=2026-11-30');
    $plain->assertOk();
    $plainRow = collect($plain->json('data.units'))->firstWhere('org_unit_id', $this->unit);
    expect($plainRow['counts']['approved'])->toBe(4)
        ->and($plainRow)->not->toHaveKeys(['projected_cost', 'budgeted_cost', 'deviation_cost'])
        ->and($plain->json('data.totals'))->not->toHaveKey('projected_cost');

    prqCall($this->admin, 'GET', '/headcount?as_of=31-12-2026')->assertStatus(400);
});

test('la desviación se explica por posición y aprobar fuera de presupuesto exige excepción con motivo', function (): void {
    prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 1, 'valid_from' => '2026-01-01'])->assertCreated();
    $band = prqBand('2000.00', '4000.00', '3200.00');
    $first = prqPosition('approved', $band);
    $second = prqPosition('proposed', $band, '2026-02-01');

    $denied = prqCall($this->admin, 'POST', "/positions/{$second}/approve");
    $denied->assertStatus(422)->assertJsonPath('error.meta.rule', 'over_budget');
    $this->assertMatchesOpenApi($denied, 'post', '/positions/{id}/approve', 422);

    prqCall($this->admin, 'POST', "/positions/{$second}/approve", ['reason' => 'Proyecto urgente del cliente'])->assertOk()->assertJsonPath('data.status', 'approved');
    $events = prqCall($this->admin, 'GET', "/positions/{$second}/status-events");
    expect($events->json('data.0.reason'))->toBe('Excepción de presupuesto: Proyecto urgente del cliente');

    $explain = prqCall($this->admin, 'GET', '/headcount/positions?org_unit_id='.$this->unit.'&as_of=2026-11-30');
    $explain->assertOk();
    $this->assertMatchesOpenApi($explain, 'get', '/headcount/positions', 200);
    $rows = collect($explain->json('data.positions'))->keyBy('position_id');
    expect($rows[$first]['budget_status'])->toBe('within')
        ->and($rows[$second]['budget_status'])->toBe('over_budget')
        ->and($rows[$second]['cost_basis'])->toBe('band_midpoint')
        ->and($rows[$second]['projected_cost'])->toBe(['amount' => '3200.00', 'currency' => 'PEN'])
        ->and($rows[$second]['explanation'])->toContain('excede el presupuesto');

    $recruiter = prqUser(PRQ_TENANT, 'recruiter');
    $plain = prqCall($recruiter, 'GET', '/headcount/positions?org_unit_id='.$this->unit);
    expect($plain->json('data.positions.0'))->not->toHaveKeys(['projected_cost', 'base_cost'])
        ->and($plain->json('data.positions.0.budget_status'))->not->toBeNull();
    prqCall($this->admin, 'GET', '/headcount/positions')->assertStatus(400);
});

test('aislamiento: otra organización no ve solicitudes, presupuestos ni unidades ajenas', function (): void {
    [$id] = prqSubmitted();
    $budget = prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 2, 'valid_from' => '2026-01-01']);
    $intruder = prqUser(PRQ_OTHER, 'admin_empresa');

    prqCall($intruder, 'GET', "/position-requests/{$id}")->assertNotFound();
    prqCall($intruder, 'POST', "/position-requests/{$id}/cancel")->assertNotFound();
    prqCall($intruder, 'GET', '/headcount-budgets/'.$budget->json('data.id'))->assertNotFound();
    prqCall($intruder, 'PATCH', '/headcount-budgets/'.$budget->json('data.id'), ['budgeted_positions' => 9])->assertNotFound();
    prqCall($intruder, 'GET', '/headcount/positions?org_unit_id='.$this->unit)->assertNotFound();
    expect(prqCall($intruder, 'GET', '/position-requests')->json('data'))->toBe([])
        ->and(prqCall($intruder, 'GET', '/headcount')->json('data.units'))->toBe([]);
});

// ---------------------------------------------------------------- POS-7 · Organigrama por posiciones

test('POS-7 · el organigrama por posiciones dibuja vacantes y congeladas y suma por rama ocupadas sobre aprobadas', function (): void {
    $band = prqBand('2000.00', '4000.00');
    $boss = prqPosition('approved', $band);
    $filled = prqPosition('approved', $band);
    $vacant = prqPosition('approved', $band);
    $frozen = prqPosition('frozen', $band);
    $proposed = prqPosition('proposed');
    TenancyScope::runAs(PRQ_TENANT, function () use ($boss, $filled, $vacant, $frozen): void {
        DB::table('positions')->whereIn('id', [$filled, $vacant, $frozen])->update(['reports_to_position_id' => $boss]);
        $employment = EmploymentCostDistributionFactory::employment($this->entity);
        DB::table('employment_assignments')->insert(['tenant_id' => PRQ_TENANT, 'employment_id' => $employment, 'job_profile_id' => $this->profile,
            'position_id' => $filled, 'org_unit_id' => $this->unit, 'site_id' => $this->site, 'valid_from' => '2026-01-01', 'reason' => 'Ingreso']);
        DB::table('employment_compensations')->insert(['tenant_id' => PRQ_TENANT, 'employment_id' => $employment, 'base_salary' => '3500.00',
            'currency' => 'PEN', 'valid_from' => '2026-01-01']);
        app(PositionOccupancy::class)->sync($filled);
    });

    $chart = prqCall($this->admin, 'GET', '/positions/org-chart?as_of=2026-11-30');
    $chart->assertOk();
    $this->assertMatchesOpenApi($chart, 'get', '/positions/org-chart', 200);
    $nodes = collect($chart->json('data'))->keyBy('id');
    expect($nodes->has($proposed))->toBeFalse()
        ->and($nodes[$filled]['chart_state'])->toBe('filled')
        ->and($nodes[$filled]['occupants'])->toHaveCount(1)
        ->and($nodes[$vacant]['chart_state'])->toBe('vacant')
        ->and($nodes[$frozen]['chart_state'])->toBe('frozen')
        ->and($nodes[$vacant]['parent_id'])->toBe($boss)
        ->and($nodes[$boss]['parent_id'])->toBeNull()
        // La rama del jefe: 4 plazas aprobadas, 1 ocupada, 1 congelada y 2 vacantes.
        ->and($nodes[$boss]['branch'])->toMatchArray(['positions' => 4, 'approved_seats' => 4, 'occupied_seats' => 1, 'frozen_seats' => 1, 'vacant_seats' => 2])
        // 3500 del ocupante + 3 plazas al punto medio 3000 (sin cargas) = 12 500.
        ->and($nodes[$boss]['branch_projected_cost'])->toBe([['amount' => '12500.00', 'currency' => 'PEN']])
        ->and($chart->json('meta.totals.approved_seats'))->toBe(4);

    // Sin permiso para ver costos de posiciones, el costo de la rama no viaja.
    $recruiter = prqUser(PRQ_TENANT, 'recruiter');
    $plain = prqCall($recruiter, 'GET', '/positions/org-chart?as_of=2026-11-30');
    $plain->assertOk();
    $plainBoss = collect($plain->json('data'))->firstWhere('id', $boss);
    expect($plainBoss)->not->toHaveKey('branch_projected_cost')->and($plainBoss['branch']['occupied_seats'])->toBe(1);

    prqCall($this->admin, 'GET', '/positions/org-chart?as_of=30-11-2026')->assertStatus(400);
    $noPermission = prqUser(PRQ_TENANT, 'empleado');
    $this->assertMatchesOpenApi(prqCall($noPermission, 'GET', '/positions/org-chart'), 'get', '/positions/org-chart', 403);
    $other = prqUser(PRQ_OTHER, 'admin_empresa');
    $foreign = prqCall($other, 'GET', '/positions/org-chart?as_of=2026-11-30');
    $foreign->assertOk();
    expect(collect($foreign->json('data'))->pluck('id')->intersect([$boss, $filled])->all())->toBe([]);
});

// ---------------------------------------------------------------- CFG-7 · Consolidado en una moneda

test('CFG-7 · el tablero consolidado convierte cada moneda e indica el tipo de cambio aplicado', function (): void {
    prqCall($this->hr, 'POST', '/headcount-budgets', ['org_unit_id' => $this->unit, 'budgeted_positions' => 2,
        'monthly_cost' => ['amount' => '1000.00', 'currency' => 'USD'], 'valid_from' => '2026-01-01'])->assertCreated();
    prqPosition('approved', prqBand('2000.00', '4000.00'));
    prqCall($this->admin, 'POST', '/exchange-rates', ['from_currency' => 'USD', 'to_currency' => 'PEN', 'rate' => '3.75', 'valid_on' => '2026-11-01', 'source' => 'Prueba'])->assertCreated();

    $board = prqCall($this->admin, 'GET', '/headcount?as_of=2026-11-30&report_currency=PEN');
    $board->assertOk();
    $this->assertMatchesOpenApi($board, 'get', '/headcount', 200);
    $c = $board->json('data.totals.consolidated');
    // El costo proyectado de la unidad está en dólares (moneda del presupuesto) y la plaza en soles: va aparte.
    expect($c['currency'])->toBe('PEN')
        ->and($c['budgeted_cost'])->toBe(['amount' => '3750.00', 'currency' => 'PEN'])
        ->and($c['rates'][0])->toMatchArray(['from' => 'USD', 'to' => 'PEN', 'rate' => '3.75', 'rate_date' => '2026-11-01', 'referential' => true])
        ->and($c['referential'])->toBeTrue()
        // Los montos originales no cambian.
        ->and($board->json('data.totals.budgeted_cost'))->toBe([['amount' => '1000.00', 'currency' => 'USD']]);

    // Sin tipo de cambio para una moneda: 422 explicado; moneda desconocida: 400.
    prqCall($this->admin, 'GET', '/headcount?as_of=2026-11-30&report_currency=EUR')->assertStatus(422);
    prqCall($this->admin, 'GET', '/headcount?as_of=2026-11-30&report_currency=pen')->assertStatus(400);
    // Quien no ve costos no recibe el consolidado.
    $plain = prqCall(prqUser(PRQ_TENANT, 'recruiter'), 'GET', '/headcount?as_of=2026-11-30&report_currency=PEN');
    expect($plain->json('data.totals'))->not->toHaveKey('consolidated');
});
