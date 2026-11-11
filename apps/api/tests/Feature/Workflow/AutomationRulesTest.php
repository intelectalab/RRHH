<?php

/**
 * WFL-5 · Reglas CUANDO → SI → ENTONCES.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Application\AutomationRunner;
use App\Modules\Workflow\Domain\Automation\RuleConditions;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Modules\Workflow\Infrastructure\Models\AutomationRun;
use App\Shared\Outbox\Commands\DispatchOutboxCommand;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const AUT_TENANT = 'aaaaaaaa-5050-4000-8000-00000000000a';
const AUT_OTHER = 'bbbbbbbb-5050-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([[AUT_TENANT, 'aut-org', 'Grupo A'], [AUT_OTHER, 'aut-otra', 'Grupo B']] as [$id, $slug, $name]) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => $name, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
    }

    $this->admin = autUser(AUT_TENANT, 'admin_empresa', 'Ana Admin');
    $this->hr = autUser(AUT_TENANT, 'rrhh', 'Hugo RRHH');
    $this->employee = autUser(AUT_TENANT, 'empleado', 'Elsa Empleada');
    $this->otherAdmin = autUser(AUT_OTHER, 'admin_empresa', 'Beto Admin');
    $this->rrhhRole = TenancyScope::runAs(AUT_TENANT, fn () => (string) Role::query()->where('system_role_code', 'rrhh')->value('id'));
});

afterEach(fn () => TenancyScope::clear());

function autUser(string $tenant, string $systemRole, string $name): User
{
    return TenancyScope::runAs($tenant, function () use ($systemRole, $name): User {
        $user = User::factory()->create(['display_name' => $name]);
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->value('id')]);

        return $user;
    });
}

function autCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/**
 * Registra un evento de dominio en el outbox de la organización A y lo despacha.
 */
function autEvent(string $type, array $payload): OutboxEvent
{
    $event = TenancyScope::runAs(AUT_TENANT, fn () => DB::transaction(fn () => app(OutboxService::class)->record($type, 'employee', (string) Str::uuid(), $payload)));
    autDispatch($event);

    return $event;
}

function autDispatch(OutboxEvent $event): void
{
    TenancyScope::runAs(AUT_TENANT, fn () => DispatchOutboxCommand::processOutbox(app(OutboxService::class), 10, 3, [$event->id]));
}

function autRule(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Renuncias: preparar liquidación',
        'trigger_event' => DomainEventTypes::EMPLOYEE_TERMINATED,
        'conditions' => ['match' => 'all', 'rules' => [['field' => 'reason', 'op' => 'eq', 'value' => 'renuncia']]],
        'actions' => [
            ['type' => 'create_task', 'title' => 'Liquidar a {{employee_id}} ({{termination_date}})', 'assignee_role_id' => test()->rrhhRole, 'due_in_days' => 2, 'priority' => 'high'],
            ['type' => 'notify', 'user_ids' => [test()->hr->id], 'title' => 'Cese registrado', 'message' => 'Cese por {{reason}} el {{termination_date}}.'],
        ],
    ];
}

function autCount(string $table, array $where): int
{
    return TenancyScope::runAs(AUT_TENANT, fn () => DB::table($table)->where($where)->count());
}

test('una regla activa reacciona al evento: crea la tarea, avisa y deja la ejecución', function (): void {
    $created = autCall($this->admin, 'POST', '/workflows', autRule());
    $created->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.allowed_actions', ['activate', 'retire']);
    $this->assertMatchesOpenApi($created, 'post', '/workflows', 201);
    $id = $created->json('data.id');

    $active = autCall($this->admin, 'POST', "/workflows/{$id}/activate");
    $active->assertOk()->assertJsonPath('data.status', 'active');
    $this->assertMatchesOpenApi($active, 'post', '/workflows/{id}/activate', 200);

    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-100', 'termination_date' => '2026-11-30', 'reason' => 'renuncia']);

    TenancyScope::runAs(AUT_TENANT, function (): void {
        $task = DB::table('hr_tasks')->where('subject_type', 'automation_run')->first();
        expect($task)->not->toBeNull()
            ->and($task->title)->toBe('Liquidar a E-100 (2026-11-30)')
            ->and($task->assignee_role_id)->toBe($this->rrhhRole)
            ->and($task->priority)->toBe('high')
            ->and($task->due_on)->toBe(now()->addDays(2)->toDateString());
        expect(DB::table('notifications')->where('category', 'automation.notice')->where('recipient_user_id', $this->hr->id)->count())->toBe(1);
    });

    $runs = autCall($this->hr, 'GET', '/workflow-runs');
    $runs->assertOk()->assertJsonPath('data.0.status', 'succeeded')->assertJsonPath('data.0.workflow_id', $id)
        ->assertJsonPath('data.0.actions.0.status', 'succeeded')->assertJsonPath('data.0.actions.1.result.notified', 1);
    $this->assertMatchesOpenApi($runs, 'get', '/workflow-runs', 200);

    $one = autCall($this->hr, 'GET', '/workflow-runs/'.$runs->json('data.0.id'));
    $one->assertOk();
    $this->assertMatchesOpenApi($one, 'get', '/workflow-runs/{id}', 200);

    $list = autCall($this->hr, 'GET', '/workflows');
    $list->assertOk()->assertJsonPath('data.0.id', $id);
    expect($list->json('data.0.last_run_at'))->not->toBeNull();
    $this->assertMatchesOpenApi($list, 'get', '/workflows', 200);

    $show = autCall($this->hr, 'GET', "/workflows/{$id}");
    $this->assertMatchesOpenApi($show, 'get', '/workflows/{id}', 200);

    TenancyScope::runAs(AUT_TENANT, fn () => expect(DB::table('audit_events')->where('entity_id', $id)->pluck('action')->all())->toContain('automation_rule.created', 'automation_rule.activate'));
});

test('el mismo evento entregado dos veces no repite el efecto', function (): void {
    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');
    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();

    $event = autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-200', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);
    // Segunda entrega: el despacho vuelve a tomarlo y además el ejecutor recibe el mismo evento directamente.
    TenancyScope::runAs(AUT_TENANT, fn () => OutboxEvent::query()->whereKey($event->id)->update(['status' => OutboxEvent::STATUS_PENDING]));
    autDispatch($event);
    $again = TenancyScope::runAs(AUT_TENANT, fn () => app(AutomationRunner::class)->dispatch(AUT_TENANT, $event->id, $event->event_type, $event->payload));

    expect($again)->toBe(0)
        ->and(autCount('hr_tasks', ['subject_type' => 'automation_run']))->toBe(1)
        ->and(autCount('notifications', ['category' => 'automation.notice']))->toBe(1)
        ->and(autCount('automation_runs', ['automation_rule_id' => $id]))->toBe(1);
});

test('si la condición no se cumple la ejecución queda omitida; una regla en borrador o pausada no corre', function (): void {
    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');
    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-1', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);
    expect(autCount('automation_runs', ['automation_rule_id' => $id]))->toBe(0);

    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-2', 'termination_date' => '2026-12-01', 'reason' => 'despido']);
    expect(autCount('automation_runs', ['automation_rule_id' => $id, 'status' => 'skipped']))->toBe(1)
        ->and(autCount('hr_tasks', ['subject_type' => 'automation_run']))->toBe(0);

    $paused = autCall($this->admin, 'POST', "/workflows/{$id}/pause");
    $paused->assertOk()->assertJsonPath('data.status', 'paused');
    $this->assertMatchesOpenApi($paused, 'post', '/workflows/{id}/pause', 200);
    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-3', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);
    expect(autCount('automation_runs', ['automation_rule_id' => $id]))->toBe(1);
});

test('una acción que falla deja la ejecución fallida, no ejecuta las siguientes y se reintenta desde ella', function (): void {
    $assignee = autUser(AUT_TENANT, 'rrhh', 'Rita Suspendida');
    TenancyScope::runAs(AUT_TENANT, fn () => DB::table('users')->where('id', $assignee->id)->update(['status' => 'disabled']));
    $id = autCall($this->admin, 'POST', '/workflows', autRule(['actions' => [
        ['type' => 'notify', 'user_ids' => [$this->hr->id], 'title' => 'Cese', 'message' => 'Cese de {{employee_id}}'],
        ['type' => 'create_task', 'title' => 'Liquidar', 'assignee_user_id' => $assignee->id],
        ['type' => 'notify', 'user_ids' => [$this->admin->id], 'title' => 'Listo', 'message' => 'Tarea creada'],
    ]]))->json('data.id');
    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();

    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-9', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);
    $run = TenancyScope::runAs(AUT_TENANT, fn () => AutomationRun::query()->where('automation_rule_id', $id)->firstOrFail());
    expect($run->status)->toBe('failed')
        ->and($run->error)->not->toBeEmpty()
        ->and(array_column($run->log['actions'], 'status'))->toBe(['succeeded', 'failed', 'not_run'])
        ->and($run->log['next_retry_at'] ?? null)->not->toBeNull()
        ->and(autCount('notifications', ['category' => 'automation.notice', 'recipient_user_id' => $this->admin->id]))->toBe(0);

    TenancyScope::runAs(AUT_TENANT, fn () => DB::table('users')->where('id', $assignee->id)->update(['status' => 'active']));
    $retried = autCall($this->admin, 'POST', "/workflow-runs/{$run->id}/retry");
    $retried->assertOk()->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.attempts', 2);
    $this->assertMatchesOpenApi($retried, 'post', '/workflow-runs/{id}/retry', 200);

    // La primera acción no se repite; las otras dos sí corren.
    expect(autCount('notifications', ['category' => 'automation.notice', 'recipient_user_id' => $this->hr->id]))->toBe(1)
        ->and(autCount('notifications', ['category' => 'automation.notice', 'recipient_user_id' => $this->admin->id]))->toBe(1)
        ->and(autCount('hr_tasks', ['assignee_user_id' => $assignee->id]))->toBe(1);

    $again = autCall($this->admin, 'POST', "/workflow-runs/{$run->id}/retry");
    $again->assertStatus(422)->assertJsonPath('error.meta.rule', 'RN-WFL-10');
    $this->assertMatchesOpenApi($again, 'post', '/workflow-runs/{id}/retry', 422);
});

test('los reintentos programados corren al vencer el plazo y una fallida se puede cancelar', function (): void {
    $id = autCall($this->admin, 'POST', '/workflows', autRule(['actions' => [
        ['type' => 'start_approval', 'subject_type' => 'vacation_request', 'subject_id_field' => 'employee_id', 'requester_field' => 'employee_id'],
    ]]))->json('data.id');
    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => (string) Str::uuid(), 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);

    // Sin flujo publicado la aprobación no se inicia (RN-WFL-01): queda fallida y se reintenta después.
    $run = TenancyScope::runAs(AUT_TENANT, fn () => AutomationRun::query()->where('automation_rule_id', $id)->firstOrFail());
    expect($run->status)->toBe('failed');
    $this->travel(10)->minutes();
    TenancyScope::runAs(AUT_TENANT, fn () => app(AutomationRunner::class)->retryDue());
    expect($run->refresh()->log['attempts'])->toBe(2)->and($run->status)->toBe('failed');

    $cancelled = autCall($this->admin, 'POST', "/workflow-runs/{$run->id}/cancel");
    $cancelled->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.allowed_actions', []);
    $this->assertMatchesOpenApi($cancelled, 'post', '/workflow-runs/{id}/cancel', 200);
});

test('protección contra bucles: al superar el límite por hora la regla se pausa y avisa al administrador', function (): void {
    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');
    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    TenancyScope::runAs(AUT_TENANT, fn () => AutomationRun::factory()->count(100)->create(['automation_rule_id' => $id, 'status' => 'succeeded']));

    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-7', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);

    TenancyScope::runAs(AUT_TENANT, function () use ($id): void {
        expect(AutomationRule::query()->findOrFail($id)->status)->toBe('paused')
            ->and(DB::table('hr_tasks')->where('subject_type', 'automation_run')->count())->toBe(0)
            ->and(DB::table('automation_runs')->where('automation_rule_id', $id)->where('status', 'cancelled')->count())->toBe(1)
            ->and(DB::table('notifications')->where('category', 'automation.paused')->where('recipient_user_id', $this->admin->id)->count())->toBe(1)
            ->and(DB::table('audit_events')->where('entity_id', $id)->where('action', 'automation_rule.auto_paused')->count())->toBe(1);
    });
});

test('solo acciones de la lista cerrada y condiciones declarativas; si no, 422', function (): void {
    $bad = autCall($this->admin, 'POST', '/workflows', autRule(['actions' => [['type' => 'terminate_employee', 'employment_id' => 'x']]]));
    $bad->assertStatus(422);
    expect($bad->json('error.details.*.field'))->toContain('actions.0.type');
    $this->assertMatchesOpenApi($bad, 'post', '/workflows', 422);

    autCall($this->admin, 'POST', '/workflows', autRule(['conditions' => ['rules' => [['field' => 'reason; DROP TABLE', 'op' => 'eq', 'value' => 'x']]]]))->assertStatus(422);
    autCall($this->admin, 'POST', '/workflows', autRule(['conditions' => ['rules' => [['field' => 'reason', 'op' => 'php_eval', 'value' => 'x']]]]))->assertStatus(422);
    autCall($this->admin, 'POST', '/workflows', autRule(['trigger_event' => 'user.message_code_issued.v1']))->assertStatus(422);
    autCall($this->admin, 'POST', '/workflows', autRule(['actions' => [['type' => 'notify', 'title' => 'x', 'message' => 'y']]]))->assertStatus(422);
    autCall($this->admin, 'POST', '/workflows', autRule(['actions' => [['type' => 'start_approval', 'subject_type' => 'contract_termination', 'subject_id_field' => 'a', 'requester_field' => 'b']]]))->assertStatus(422);
    autCall($this->admin, 'POST', '/workflows', autRule(['actions' => []]))->assertStatus(422);
});

test('estados solo por acciones: transición inválida 409, «status» en PATCH se ignora y una activa no se edita', function (): void {
    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');

    $patch = autCall($this->admin, 'PATCH', "/workflows/{$id}", ['name' => 'Renuncias', 'status' => 'active']);
    $patch->assertOk()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.name', 'Renuncias');
    $this->assertMatchesOpenApi($patch, 'patch', '/workflows/{id}', 200);

    $invalid = autCall($this->admin, 'POST', "/workflows/{$id}/pause");
    $invalid->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($invalid, 'post', '/workflows/{id}/pause', 409);

    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    autCall($this->admin, 'PATCH', "/workflows/{$id}", ['actions' => autRule()['actions']])->assertStatus(422);
    autCall($this->admin, 'PATCH', "/workflows/{$id}", ['name' => 'Renuncias (Lima)'])->assertOk();

    $retired = autCall($this->admin, 'POST', "/workflows/{$id}/retire");
    $retired->assertOk()->assertJsonPath('data.status', 'retired');
    $this->assertMatchesOpenApi($retired, 'post', '/workflows/{id}/retire', 200);
    autCall($this->admin, 'GET', "/workflows/{$id}")->assertNotFound();
});

test('catálogo y prueba en seco sin efectos', function (): void {
    $catalog = autCall($this->hr, 'GET', '/workflows/catalog');
    $catalog->assertOk();
    $this->assertMatchesOpenApi($catalog, 'get', '/workflows/catalog', 200);
    $events = collect($catalog->json('data.triggers'))->pluck('event');
    expect($events)->toContain(DomainEventTypes::EMPLOYEE_TERMINATED)->not->toContain(DomainEventTypes::USER_MESSAGE_CODE_ISSUED)
        ->and(collect($catalog->json('data.actions'))->pluck('type')->all())->toBe(['create_task', 'notify', 'start_approval']);
    $comp = collect($catalog->json('data.triggers'))->firstWhere('event', DomainEventTypes::EMPLOYEE_COMPENSATION_CHANGED);
    expect($comp['fields'])->not->toContain('new_amount');

    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');
    $test = autCall($this->admin, 'POST', "/workflows/{$id}/test", ['payload' => ['employee_id' => 'E-5', 'termination_date' => '2026-12-05', 'reason' => 'Renuncia']]);
    $test->assertOk()->assertJsonPath('data.matches', true)->assertJsonPath('data.actions.0.params.title', 'Liquidar a E-5 (2026-12-05)');
    $this->assertMatchesOpenApi($test, 'post', '/workflows/{id}/test', 200);
    expect(autCount('hr_tasks', ['subject_type' => 'automation_run']))->toBe(0)
        ->and(autCount('automation_runs', ['automation_rule_id' => $id]))->toBe(0);

    autCall($this->admin, 'POST', "/workflows/{$id}/test", ['payload' => ['reason' => 'despido']])->assertOk()->assertJsonPath('data.matches', false);
    autCall($this->admin, 'POST', "/workflows/{$id}/test", ['payload' => 'texto'])->assertStatus(422);
});

test('sin permiso 403: RRHH ve pero no crea ni reintenta; el colaborador no ve', function (): void {
    $forbidden = autCall($this->hr, 'POST', '/workflows', autRule());
    $forbidden->assertStatus(403);
    $this->assertMatchesOpenApi($forbidden, 'post', '/workflows', 403);

    $list = autCall($this->employee, 'GET', '/workflows');
    $list->assertStatus(403);
    $this->assertMatchesOpenApi($list, 'get', '/workflows', 403);
    autCall($this->employee, 'GET', '/workflow-runs')->assertStatus(403);

    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');
    autCall($this->hr, 'POST', "/workflows/{$id}/activate")->assertStatus(403);
    $run = TenancyScope::runAs(AUT_TENANT, fn () => AutomationRun::factory()->create(['automation_rule_id' => $id, 'status' => 'failed']));
    autCall($this->hr, 'POST', "/workflow-runs/{$run->id}/retry")->assertStatus(403);
});

test('aislamiento: otra organización no ve reglas ni ejecuciones ni las dispara', function (): void {
    $id = autCall($this->admin, 'POST', '/workflows', autRule())->json('data.id');
    autCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    autEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-8', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);
    $runId = TenancyScope::runAs(AUT_TENANT, fn () => AutomationRun::query()->where('automation_rule_id', $id)->value('id'));

    $show = autCall($this->otherAdmin, 'GET', "/workflows/{$id}");
    $show->assertNotFound();
    $this->assertMatchesOpenApi($show, 'get', '/workflows/{id}', 404);
    autCall($this->otherAdmin, 'POST', "/workflows/{$id}/pause")->assertNotFound();
    autCall($this->otherAdmin, 'GET', "/workflow-runs/{$runId}")->assertNotFound();
    autCall($this->otherAdmin, 'POST', "/workflow-runs/{$runId}/retry")->assertNotFound();
    expect(autCall($this->otherAdmin, 'GET', '/workflows')->json('data'))->toBe([])
        ->and(autCall($this->otherAdmin, 'GET', '/workflow-runs')->json('data'))->toBe([]);

    // Un evento de B no dispara reglas de A.
    $eventB = TenancyScope::runAs(AUT_OTHER, fn () => DB::transaction(fn () => app(OutboxService::class)->record(DomainEventTypes::EMPLOYEE_TERMINATED, 'employee', (string) Str::uuid(), ['employee_id' => 'B-1', 'termination_date' => '2026-12-01', 'reason' => 'renuncia'])));
    TenancyScope::runAs(AUT_OTHER, fn () => DispatchOutboxCommand::processOutbox(app(OutboxService::class), 10, 3, [$eventB->id]));
    expect(autCount('automation_runs', ['automation_rule_id' => $id]))->toBe(1);
});

test('condiciones: operadores declarativos y comparación numérica exacta', function (): void {
    $payload = ['days' => '10.50', 'reason' => 'Renuncia voluntaria', 'tags' => ['lima', 'ventas'], 'area' => ['name' => 'TI']];
    $c = fn (string $field, string $op, mixed $value = null) => RuleConditions::matches(['match' => 'all', 'rules' => [['field' => $field, 'op' => $op, 'value' => $value]]], $payload);

    expect($c('days', 'gt', '10.5'))->toBeFalse()
        ->and($c('days', 'gte', 10.5))->toBeTrue()
        ->and($c('days', 'lt', 11))->toBeTrue()
        ->and($c('reason', 'contains', 'renuncia'))->toBeTrue()
        ->and($c('tags', 'contains', 'Lima'))->toBeTrue()
        ->and($c('area.name', 'in', ['ti', 'rrhh']))->toBeTrue()
        ->and($c('area.name', 'not_in', ['ti']))->toBeFalse()
        ->and($c('missing', 'not_exists'))->toBeTrue()
        ->and($c('reason', 'neq', 'despido'))->toBeTrue()
        ->and(RuleConditions::matches(['match' => 'any', 'rules' => [['field' => 'reason', 'op' => 'eq', 'value' => 'x'], ['field' => 'area.name', 'op' => 'eq', 'value' => 'TI']]], $payload))->toBeTrue()
        ->and(RuleConditions::errors(['match' => 'all', 'rules' => [['field' => 'days', 'op' => 'gt', 'value' => 'diez']]]))->toBe(['conditions.rules.0.value']);
});
