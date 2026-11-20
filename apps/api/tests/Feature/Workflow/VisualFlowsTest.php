<?php

/**
 * WFL-6 · Constructor visual de flujos · WFL-7 · Centro de automatizaciones.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Application\AutomationRunner;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Modules\Workflow\Infrastructure\Models\AutomationRun;
use App\Shared\Outbox\Commands\DispatchOutboxCommand;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const VF_TENANT = 'aaaaaaaa-6060-4000-8000-00000000000a';
const VF_OTHER = 'bbbbbbbb-6060-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    foreach ([[VF_TENANT, 'vf-org', 'Grupo VF'], [VF_OTHER, 'vf-otra', 'Grupo VF B']] as [$id, $slug, $name]) {
        DB::table('tenants')->insertOrIgnore(['id' => $id, 'slug' => $slug, 'name' => $name, 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
        DB::select('SELECT seed_tenant_defaults(?)', [$id]);
    }
    $this->admin = vfUser(VF_TENANT, 'admin_empresa', 'Ana Admin');
    $this->hr = vfUser(VF_TENANT, 'rrhh', 'Hugo RRHH');
    $this->otherAdmin = vfUser(VF_OTHER, 'admin_empresa', 'Beto Admin');
    $this->rrhhRole = TenancyScope::runAs(VF_TENANT, fn () => (string) Role::query()->where('system_role_code', 'rrhh')->value('id'));
});

afterEach(fn () => TenancyScope::clear());

function vfUser(string $tenant, string $systemRole, string $name): User
{
    return TenancyScope::runAs($tenant, function () use ($systemRole, $name): User {
        $user = User::factory()->create(['display_name' => $name]);
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->value('id')]);

        return $user;
    });
}

function vfCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

function vfEvent(string $type, array $payload): void
{
    $payload += ['employee_id' => 'E-0', 'termination_date' => '2026-12-01', 'reason' => 'renuncia'];
    $event = TenancyScope::runAs(VF_TENANT, fn () => DB::transaction(fn () => app(OutboxService::class)->record($type, 'employee', (string) Str::uuid(), $payload)));
    TenancyScope::runAs(VF_TENANT, fn () => DispatchOutboxCommand::processOutbox(app(OutboxService::class), 10, 3, [$event->id]));
}

/**
 * Cese → ¿renuncia? sí: tarea a RRHH, espera 2 días, aviso y fin · no: fin.
 */
function vfGraph(string $assigneeUserId, string $notifyUserId): array
{
    return [
        'nodes' => [
            ['id' => 'start', 'kind' => 'start', 'label' => 'Cese', 'x' => 0, 'y' => 0, 'config' => ['trigger_event' => DomainEventTypes::EMPLOYEE_TERMINATED]],
            ['id' => 'c1', 'kind' => 'condition', 'label' => '¿Renuncia?', 'config' => ['match' => 'all', 'rules' => [['field' => 'reason', 'op' => 'eq', 'value' => 'renuncia']]]],
            ['id' => 'a1', 'kind' => 'action', 'label' => 'Tarea', 'config' => ['title' => 'Liquidar a {{employee_id}}', 'assignee_user_id' => $assigneeUserId]],
            ['id' => 'w1', 'kind' => 'wait', 'label' => 'Esperar', 'config' => ['days' => 2]],
            ['id' => 'n1', 'kind' => 'notification', 'label' => 'Aviso', 'config' => ['title' => 'Seguimiento', 'message' => 'Revisa la liquidación de {{employee_id}}', 'user_ids' => [$notifyUserId]]],
            ['id' => 'e1', 'kind' => 'end', 'label' => 'Fin'],
            ['id' => 'e2', 'kind' => 'end', 'label' => 'Fin'],
        ],
        'edges' => [
            ['from' => 'start', 'to' => 'c1'],
            ['from' => 'c1', 'to' => 'a1', 'branch' => 'yes'],
            ['from' => 'c1', 'to' => 'e2', 'branch' => 'no'],
            ['from' => 'a1', 'to' => 'w1'],
            ['from' => 'w1', 'to' => 'n1'],
            ['from' => 'n1', 'to' => 'e1'],
        ],
    ];
}

test('WFL-6 · el constructor guarda borradores, valida el diseño y no publica nodos sueltos o sin salida', function (): void {
    $created = vfCall($this->admin, 'POST', '/visual-flows', ['name' => 'Ceses']);
    $created->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.version', 0);
    $this->assertMatchesOpenApi($created, 'post', '/visual-flows', 201);
    $id = $created->json('data.id');
    // Lienzo nuevo: inicio y fin sin conectar ni configurar.
    expect(array_column($created->json('data.issues'), 'code'))->toContain('disconnected', 'invalid_config');

    $graph = vfGraph($this->hr->id, $this->admin->id);
    $graph['nodes'][] = ['id' => 'loose', 'kind' => 'notification', 'config' => ['title' => 'x', 'message' => 'y', 'user_ids' => [$this->admin->id]]];
    unset($graph['edges'][5]); // n1 sin salida
    $saved = vfCall($this->admin, 'PATCH', "/visual-flows/{$id}", ['graph' => $graph]);
    $saved->assertOk();
    $this->assertMatchesOpenApi($saved, 'patch', '/visual-flows/{id}', 200);
    $codes = collect($saved->json('data.issues'))->mapWithKeys(fn ($i) => [$i['node'] ?? '_' => $i['code']]);
    expect($codes->get('loose'))->toBe('disconnected')->and($codes->get('n1'))->toBe('no_exit')->and($codes->get('e1'))->toBe('disconnected');

    $blocked = vfCall($this->admin, 'POST', "/visual-flows/{$id}/publish");
    $blocked->assertStatus(422);
    $this->assertMatchesOpenApi($blocked, 'post', '/visual-flows/{id}/publish', 422);

    // Condición sin rama «no» y ciclo.
    $bad = vfGraph($this->hr->id, $this->admin->id);
    $bad['edges'] = array_values(array_filter($bad['edges'], fn ($e) => ($e['branch'] ?? null) !== 'no'));
    $bad['edges'][] = ['from' => 'n1', 'to' => 'a1'];
    $issues = vfCall($this->admin, 'PATCH', "/visual-flows/{$id}", ['graph' => $bad])->json('data.issues');
    expect(array_column($issues, 'code'))->toContain('condition_branches', 'cycle');

    vfCall($this->admin, 'PATCH', "/visual-flows/{$id}", ['graph' => ['nodes' => 'x']])->assertStatus(422);
});

test('WFL-6 · el modo de prueba muestra el recorrido de un caso de ejemplo sin efectos', function (): void {
    $id = vfCall($this->admin, 'POST', '/visual-flows', ['name' => 'Ceses', 'graph' => vfGraph($this->hr->id, $this->admin->id)])->json('data.id');

    $yes = vfCall($this->admin, 'POST', "/visual-flows/{$id}/test", ['payload' => ['employee_id' => 'E-1', 'reason' => 'renuncia']]);
    $yes->assertOk()->assertJsonPath('data.reached_end', true)->assertJsonPath('data.issues', []);
    $this->assertMatchesOpenApi($yes, 'post', '/visual-flows/{id}/test', 200);
    expect(array_column($yes->json('data.path'), 'node'))->toBe(['start', 'c1', 'a1', 'w1', 'n1', 'e1'])
        ->and($yes->json('data.path.1.branch'))->toBe('yes')
        ->and($yes->json('data.path.1.conditions.0.result'))->toBeTrue()
        ->and($yes->json('data.path.2.params.title'))->toBe('Liquidar a E-1')
        ->and($yes->json('data.path.3.status'))->toBe('would_wait');

    $no = vfCall($this->admin, 'POST', "/visual-flows/{$id}/test", ['payload' => ['employee_id' => 'E-2', 'reason' => 'despido']]);
    expect(array_column($no->json('data.path'), 'node'))->toBe(['start', 'c1', 'e2']);
    expect(TenancyScope::runAs(VF_TENANT, fn () => DB::table('hr_tasks')->count()))->toBe(0);
});

test('WFL-6 · publicar materializa la versión, la regla asociada corre el grafo, espera y retoma; republicar no corta lo en curso', function (): void {
    $id = vfCall($this->admin, 'POST', '/visual-flows', ['name' => 'Ceses', 'graph' => vfGraph($this->hr->id, $this->admin->id)])->json('data.id');
    $published = vfCall($this->admin, 'POST', "/visual-flows/{$id}/publish");
    $published->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.version', 1);
    $this->assertMatchesOpenApi($published, 'post', '/visual-flows/{id}/publish', 200);
    vfCall($this->admin, 'POST', "/visual-flows/{$id}/publish")->assertStatus(409);

    TenancyScope::runAs(VF_TENANT, function () use ($published): void {
        $versionId = DB::table('workflow_definitions')->where('id', $published->json('data.id'))->value('current_version_id');
        expect(DB::table('workflow_steps')->where('workflow_definition_version_id', $versionId)->count())->toBe(7)
            ->and(DB::table('workflow_transitions')->where('workflow_definition_version_id', $versionId)->count())->toBe(6);
    });
    $ruleId = $published->json('data.workflow_id');
    expect($ruleId)->not->toBeNull();
    // Los flujos del constructor no aparecen entre los flujos de aprobación.
    expect(collect(vfCall($this->admin, 'GET', '/approval-flows')->json('data'))->pluck('id'))->not->toContain($id);

    vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-7', 'termination_date' => '2026-12-01', 'reason' => 'renuncia']);
    $run = TenancyScope::runAs(VF_TENANT, fn () => AutomationRun::query()->where('automation_rule_id', $ruleId)->firstOrFail());
    expect($run->status)->toBe('waiting')
        ->and(array_column($run->log['path'], 'node'))->toBe(['start', 'c1', 'a1', 'w1'])
        ->and(TenancyScope::runAs(VF_TENANT, fn () => DB::table('hr_tasks')->where('title', 'Liquidar a E-7')->count()))->toBe(1);

    // Una versión nueva (sin la tarea) no cambia la ejecución en curso.
    $v2 = vfGraph($this->hr->id, $this->admin->id);
    $v2['nodes'][2]['config']['title'] = 'Otra tarea';
    vfCall($this->admin, 'PATCH', "/visual-flows/{$id}", ['graph' => $v2])->assertOk()->assertJsonPath('data.has_draft', true);
    vfCall($this->admin, 'POST', "/visual-flows/{$id}/publish")->assertOk()->assertJsonPath('data.version', 2);

    $this->travel(3)->days();
    TenancyScope::runAs(VF_TENANT, fn () => app(AutomationRunner::class)->resumeDue());
    $run->refresh();
    expect($run->status)->toBe('succeeded')
        ->and(array_column($run->log['path'], 'node'))->toBe(['start', 'c1', 'a1', 'w1', 'n1', 'e1'])
        ->and(TenancyScope::runAs(VF_TENANT, fn () => DB::table('notifications')->where('category', 'automation.notice')->where('recipient_user_id', $this->admin->id)->count()))->toBe(1);

    // La regla del constructor no se edita ni se retira desde Automatizaciones (409), pero sí se pausa.
    vfCall($this->admin, 'PATCH', "/workflows/{$ruleId}", ['name' => 'x'])->assertStatus(409)->assertJsonPath('error.code', 'managed_by_flow');
    vfCall($this->admin, 'POST', "/workflows/{$ruleId}/retire")->assertStatus(409);
    vfCall($this->admin, 'POST', "/workflows/{$ruleId}/pause")->assertOk();
    vfCall($this->admin, 'GET', "/visual-flows/{$id}")->assertJsonPath('data.status', 'paused');

    $active = vfCall($this->admin, 'POST', "/visual-flows/{$id}/activate");
    $active->assertOk()->assertJsonPath('data.status', 'active');
    $this->assertMatchesOpenApi($active, 'post', '/visual-flows/{id}/activate', 200);
    $retired = vfCall($this->admin, 'POST', "/visual-flows/{$id}/retire");
    $retired->assertOk()->assertJsonPath('data.status', 'retired');
    expect(TenancyScope::runAs(VF_TENANT, fn () => AutomationRule::withTrashed()->find($ruleId)?->trashed()))->toBeTrue();
    vfCall($this->admin, 'POST', "/visual-flows/{$id}/pause")->assertStatus(404);
});

test('WFL-6 · un nodo de acción que falla deja la ejecución fallida en ese nodo y el reintento sigue desde ahí', function (): void {
    $assignee = vfUser(VF_TENANT, 'rrhh', 'Rita Suspendida');
    TenancyScope::runAs(VF_TENANT, fn () => DB::table('users')->where('id', $assignee->id)->update(['status' => 'disabled']));
    $graph = vfGraph($assignee->id, $this->admin->id);
    $graph['nodes'][3]['kind'] = 'notification';
    $graph['nodes'][3]['config'] = ['title' => 'Hecho', 'message' => 'ok', 'user_ids' => [$this->hr->id]];
    $id = vfCall($this->admin, 'POST', '/visual-flows', ['name' => 'Ceses', 'graph' => $graph])->json('data.id');
    $ruleId = vfCall($this->admin, 'POST', "/visual-flows/{$id}/publish")->json('data.workflow_id');

    vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-9', 'reason' => 'renuncia']);
    $run = TenancyScope::runAs(VF_TENANT, fn () => AutomationRun::query()->where('automation_rule_id', $ruleId)->firstOrFail());
    expect($run->status)->toBe('failed')->and($run->log['cursor'])->toBe('a1');

    TenancyScope::runAs(VF_TENANT, fn () => DB::table('users')->where('id', $assignee->id)->update(['status' => 'active']));
    $retried = vfCall($this->admin, 'POST', "/workflow-runs/{$run->id}/retry");
    $retried->assertOk()->assertJsonPath('data.status', 'succeeded');
    $this->assertMatchesOpenApi($retried, 'post', '/workflow-runs/{id}/retry', 200);
    expect(array_column($retried->json('data.path'), 'status'))->toBe(['passed', 'evaluated', 'failed', 'succeeded', 'succeeded', 'succeeded', 'end']);
});

test('WFL-7 · cada ejecución muestra qué la disparó, qué condiciones evaluó y qué hizo; la regla muestra sus números', function (): void {
    $id = vfCall($this->admin, 'POST', '/workflows', [
        'name' => 'Renuncias', 'trigger_event' => DomainEventTypes::EMPLOYEE_TERMINATED,
        'conditions' => ['match' => 'all', 'rules' => [['field' => 'reason', 'op' => 'eq', 'value' => 'renuncia']]],
        'actions' => [['type' => 'notify', 'user_ids' => [$this->hr->id], 'title' => 'Cese', 'message' => 'Cese de {{employee_id}}']],
    ])->assertCreated()->assertJsonPath('data.owner_user_id', $this->admin->id)->json('data.id');
    vfCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-3', 'reason' => 'renuncia']);
    vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-4', 'reason' => 'despido']);

    $runs = vfCall($this->admin, 'GET', "/workflow-runs?filter[workflow_id]={$id}");
    $runs->assertOk();
    $this->assertMatchesOpenApi($runs, 'get', '/workflow-runs', 200);
    $byStatus = collect($runs->json('data'))->keyBy('status');
    expect($byStatus['succeeded']['event_type'])->toBe(DomainEventTypes::EMPLOYEE_TERMINATED)
        ->and($byStatus['succeeded']['payload']['employee_id'])->toBe('E-3')
        ->and($byStatus['succeeded']['conditions'][0])->toMatchArray(['field' => 'reason', 'op' => 'eq', 'actual' => 'renuncia', 'result' => true])
        ->and($byStatus['succeeded']['actions'][0]['status'])->toBe('succeeded')
        ->and($byStatus['skipped']['conditions'][0]['result'])->toBeFalse();

    $rule = vfCall($this->admin, 'GET', "/workflows/{$id}");
    $rule->assertOk()->assertJsonPath('data.stats.runs', 2)->assertJsonPath('data.stats.succeeded', 1)->assertJsonPath('data.stats.skipped', 1)
        ->assertJsonPath('data.failing', false)->assertJsonPath('data.owner_name', 'Ana Admin');
    $this->assertMatchesOpenApi($rule, 'get', '/workflows/{id}', 200);
});

test('WFL-7 · una regla con fallas seguidas se marca «con errores» y avisa una sola vez a su responsable', function (): void {
    $assignee = vfUser(VF_TENANT, 'rrhh', 'Rita Suspendida');
    TenancyScope::runAs(VF_TENANT, fn () => DB::table('users')->where('id', $assignee->id)->update(['status' => 'disabled']));
    $id = vfCall($this->admin, 'POST', '/workflows', [
        'name' => 'Tarea imposible', 'trigger_event' => DomainEventTypes::EMPLOYEE_TERMINATED, 'owner_user_id' => $this->hr->id,
        'actions' => [['type' => 'create_task', 'title' => 'Liquidar', 'assignee_user_id' => $assignee->id]],
    ])->json('data.id');
    vfCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk();
    $failing = fn () => TenancyScope::runAs(VF_TENANT, fn () => DB::table('notifications')->where('category', 'automation.failing')->where('recipient_user_id', $this->hr->id)->count());

    foreach (['E-1', 'E-2'] as $e) {
        vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => $e]);
    }
    expect($failing())->toBe(0);
    vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-3']);
    vfEvent(DomainEventTypes::EMPLOYEE_TERMINATED, ['employee_id' => 'E-4']);
    expect($failing())->toBe(1);

    vfCall($this->admin, 'GET', "/workflows/{$id}")->assertJsonPath('data.failing', true)->assertJsonPath('data.stats.failed', 4);
    expect(collect(vfCall($this->admin, 'GET', '/workflows?filter[failing]=1')->json('data'))->pluck('id')->all())->toBe([$id]);

    // Pausar y reanudar quita la marca; una ejecución correcta también.
    vfCall($this->admin, 'POST', "/workflows/{$id}/pause")->assertOk();
    vfCall($this->admin, 'POST', "/workflows/{$id}/activate")->assertOk()->assertJsonPath('data.failing', false);
});

test('WFL-6 · permisos, estados por acciones y aislamiento', function (): void {
    vfCall($this->hr, 'POST', '/visual-flows', ['name' => 'x'])->assertForbidden();
    $id = vfCall($this->admin, 'POST', '/visual-flows', ['name' => 'Ceses', 'graph' => vfGraph($this->hr->id, $this->admin->id)])->json('data.id');
    vfCall($this->hr, 'GET', "/visual-flows/{$id}")->assertOk();
    vfCall($this->hr, 'POST', "/visual-flows/{$id}/publish")->assertForbidden();

    // «status» en PATCH se ignora; pausar un borrador es una transición inválida.
    vfCall($this->admin, 'PATCH', "/visual-flows/{$id}", ['status' => 'active'])->assertOk()->assertJsonPath('data.status', 'draft');
    $invalid = vfCall($this->admin, 'POST', "/visual-flows/{$id}/pause");
    $invalid->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($invalid, 'post', '/visual-flows/{id}/pause', 409);

    vfCall($this->otherAdmin, 'GET', "/visual-flows/{$id}")->assertNotFound();
    vfCall($this->otherAdmin, 'POST', "/visual-flows/{$id}/test", ['payload' => []])->assertNotFound();
    expect(vfCall($this->otherAdmin, 'GET', '/visual-flows')->json('data'))->toBe([]);
    $list = vfCall($this->admin, 'GET', '/visual-flows');
    $this->assertMatchesOpenApi($list, 'get', '/visual-flows', 200);
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$id]);
});
