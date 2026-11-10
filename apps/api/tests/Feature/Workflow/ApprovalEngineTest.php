<?php

/**
 * WFL-1 · WFL-2 · WFL-3 · Flujos versionados, motor de N niveles, delegación, escalamiento.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Application\ApprovalEngine;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Modules\Workflow\Infrastructure\Models\WorkflowTask;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const WFL_TENANT = 'aaaaaaaa-7070-4000-8000-00000000000a';
const WFL_OTHER = 'bbbbbbbb-7070-4000-8000-00000000000b';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => WFL_TENANT, 'slug' => 'wfl-org-a', 'name' => 'Grupo A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => WFL_OTHER, 'slug' => 'wfl-org-b', 'name' => 'Grupo B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [WFL_TENANT]);
    DB::select('SELECT seed_tenant_defaults(?)', [WFL_OTHER]);

    $this->admin = wflUser('admin_empresa', 'Ana Admin');
    $this->requester = wflUser('empleado', 'Rita Solicitante');
    $this->boss = wflUser('manager', 'Beto Jefe');
    $this->director = wflUser('manager', 'Dora Directora');
    $this->hr = wflUser('rrhh', 'Hugo RRHH');
});

afterEach(fn () => TenancyScope::clear());

function wflUser(string $systemRole, string $name, string $tenant = WFL_TENANT): User
{
    return TenancyScope::runAs($tenant, function () use ($systemRole, $name): User {
        $user = User::factory()->create(['display_name' => $name]);
        $role = Role::query()->where('system_role_code', $systemRole)->firstOrFail();
        $user->roleAssignments()->create(['role_id' => $role->id]);

        return $user;
    });
}

function wflCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

/** Crea y publica un flujo por la API. */
function wflFlow(User $admin, array $levels, array $conditions = [], string $type = 'vacation_request'): string
{
    $created = wflCall($admin, 'POST', '/approval-flows', ['name' => 'Vacaciones '.Str::random(4), 'subject_type' => $type, 'steps' => ['levels' => $levels], 'conditions' => $conditions]);
    $created->assertCreated();
    wflCall($admin, 'POST', '/approval-flows/'.$created->json('data.id').'/publish', [])->assertOk();

    return (string) $created->json('data.id');
}

function wflStart(User $requester, array $context = [], string $type = 'vacation_request'): ApprovalRequest
{
    return TenancyScope::runAs(WFL_TENANT, fn () => app(ApprovalEngine::class)->start($type, (string) Str::uuid(), $requester->id, $context));
}

function wflOutbox(string $requestId, string $type): int
{
    return TenancyScope::runAs(WFL_TENANT, fn () => DB::table('outbox_events')->where('aggregate_id', $requestId)->where('event_type', $type)->count());
}

test('crear, publicar y versionar un flujo: editar no altera las solicitudes en curso', function (): void {
    $flowId = wflFlow($this->admin, [['name' => 'Jefe', 'mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]);

    $show = wflCall($this->admin, 'GET', '/approval-flows/'.$flowId);
    $show->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.version', 1);
    $this->assertMatchesOpenApi($show, 'get', '/approval-flows/{id}', 200);

    $running = wflStart($this->requester);

    $patch = wflCall($this->admin, 'PATCH', '/approval-flows/'.$flowId, ['steps' => ['levels' => [['name' => 'RRHH', 'mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->hr->id]]]]]]);
    $patch->assertOk()->assertJsonPath('data.has_draft', true)->assertJsonPath('data.version', 1);
    $this->assertMatchesOpenApi($patch, 'patch', '/approval-flows/{id}', 200);

    $publish = wflCall($this->admin, 'POST', '/approval-flows/'.$flowId.'/publish', []);
    $publish->assertOk()->assertJsonPath('data.version', 2);
    $this->assertMatchesOpenApi($publish, 'post', '/approval-flows/{id}/publish', 200);

    // La solicitud iniciada con la versión 1 sigue esperando al jefe.
    $detail = wflCall($this->boss, 'GET', '/approval-requests/'.$running->id);
    $detail->assertOk()->assertJsonPath('data.tasks.0.assignee_user_id', $this->boss->id)->assertJsonPath('data.can_decide', true);
    $this->assertMatchesOpenApi($detail, 'get', '/approval-requests/{id}', 200);

    // Una nueva usa la versión 2.
    $new = wflStart($this->requester);
    expect(TenancyScope::runAs(WFL_TENANT, fn () => WorkflowTask::query()->where('workflow_instance_id', $new->id)->value('assignee_user_id')))->toBe($this->hr->id);

    $list = wflCall($this->admin, 'GET', '/approval-flows');
    $list->assertOk();
    $this->assertMatchesOpenApi($list, 'get', '/approval-flows', 200);
});

test('un flujo inválido no se publica y uno retirado deja de usarse', function (): void {
    $bad = wflCall($this->admin, 'POST', '/approval-flows', ['name' => 'Malo', 'subject_type' => 'vacation_request', 'steps' => ['levels' => [['mode' => 'quorum_magico', 'approvers' => []]]]]);
    $bad->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($bad, 'post', '/approval-flows', 422);

    $empty = wflCall($this->admin, 'POST', '/approval-flows', ['name' => 'Vacío', 'subject_type' => 'vacation_request']);
    $empty->assertCreated()->assertJsonPath('data.status', 'draft');
    $this->assertMatchesOpenApi($empty, 'post', '/approval-flows', 201);
    wflCall($this->admin, 'POST', '/approval-flows/'.$empty->json('data.id').'/publish', [])->assertStatus(422);

    $flowId = wflFlow($this->admin, [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]);
    $retire = wflCall($this->admin, 'POST', '/approval-flows/'.$flowId.'/retire', []);
    $retire->assertOk()->assertJsonPath('data.status', 'retired');
    $this->assertMatchesOpenApi($retire, 'post', '/approval-flows/{id}/retire', 200);
    wflCall($this->admin, 'POST', '/approval-flows/'.$flowId.'/retire', [])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');

    expect(fn () => wflStart($this->requester))->toThrow(BusinessRuleViolatedException::class);
});

test('dos niveles secuenciales: jefe y luego directora; el módulo recibe el resultado una sola vez', function (): void {
    wflFlow($this->admin, [
        ['name' => 'Jefe', 'mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]],
        ['name' => 'Dirección', 'mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->director->id]]],
    ]);
    $req = wflStart($this->requester);

    // La directora aún no tiene tarea: con alcance «propio» ni siquiera la ve.
    wflCall($this->director, 'POST', '/approval-requests/'.$req->id.'/approve', [])->assertNotFound();
    // RRHH la ve, pero no le toca decidir.
    wflCall($this->hr, 'POST', '/approval-requests/'.$req->id.'/approve', [])->assertStatus(403);

    $first = wflCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/approve', ['comment' => 'Ok']);
    $first->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.current_step', 2);
    $this->assertMatchesOpenApi($first, 'post', '/approval-requests/{id}/approve', 200);

    // Repetir la misma decisión no crea otra (idempotente).
    wflCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/approve', ['comment' => 'Ok'])->assertOk();

    $final = wflCall($this->director, 'POST', '/approval-requests/'.$req->id.'/approve', []);
    $final->assertOk()->assertJsonPath('data.status', 'approved');
    wflCall($this->director, 'POST', '/approval-requests/'.$req->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');

    TenancyScope::runAs(WFL_TENANT, function () use ($req): void {
        expect(DB::table('workflow_decisions')->join('workflow_tasks as t', 't.id', '=', 'workflow_decisions.workflow_task_id')->where('t.workflow_instance_id', $req->id)->count())->toBe(2)
            ->and(DB::table('audit_events')->where('entity_id', $req->id)->where('action', 'approval_request.approved')->count())->toBe(2);
    });
    expect(wflOutbox($req->id, 'approval.approved.v1'))->toBe(1)
        ->and(wflOutbox($req->id, 'approval.step_approved.v1'))->toBe(1);
});

test('dos aprobadores en paralelo: «todos» espera a ambos y «cualquiera» cierra con uno', function (): void {
    wflFlow($this->admin, [['name' => 'Comité', 'mode' => 'parallel_all', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id], ['kind' => 'user', 'user_id' => $this->director->id]]]]);
    $all = wflStart($this->requester);
    wflCall($this->director, 'POST', '/approval-requests/'.$all->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'pending');
    wflCall($this->boss, 'POST', '/approval-requests/'.$all->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');

    // Mismo tipo de solicitud, flujo más específico por condición (RN-WFL-01).
    wflFlow($this->admin, [['name' => 'Cualquiera', 'mode' => 'parallel_any', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id], ['kind' => 'user', 'user_id' => $this->director->id]]]], ['urgent' => true]);
    $any = wflStart($this->requester, ['urgent' => true]);
    wflCall($this->director, 'POST', '/approval-requests/'.$any->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(TenancyScope::runAs(WFL_TENANT, fn () => WorkflowTask::query()->where('workflow_instance_id', $any->id)->where('assignee_user_id', $this->boss->id)->value('status')))->toBe('skipped');
});

test('rechazar exige motivo, cierra la solicitud y avisa al módulo una vez', function (): void {
    wflFlow($this->admin, [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]);
    $req = wflStart($this->requester);

    $noReason = wflCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/reject', []);
    $noReason->assertStatus(422);
    $this->assertMatchesOpenApi($noReason, 'post', '/approval-requests/{id}/reject', 422);

    $rejected = wflCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/reject', ['comment' => 'Cierre de mes']);
    $rejected->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.tasks.0.comment', 'Cierre de mes');
    $this->assertMatchesOpenApi($rejected, 'post', '/approval-requests/{id}/reject', 200);
    wflCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/reject', ['comment' => 'Cierre de mes'])->assertOk();

    // Ya rechazada: aprobar es una transición inválida.
    wflCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/approve', [])->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    expect(wflOutbox($req->id, 'approval.rejected.v1'))->toBe(1);
});

test('nadie aprueba su propia solicitud: el nivel donde el solicitante es aprobador se salta o va a RRHH', function (): void {
    wflFlow($this->admin, [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]);
    $own = wflStart($this->boss);

    // El único aprobador era el propio solicitante: la tarea va al rol RRHH.
    $task = TenancyScope::runAs(WFL_TENANT, fn () => WorkflowTask::query()->where('workflow_instance_id', $own->id)->first());
    expect($task->assignee_user_id)->toBeNull()
        ->and($task->assignee_role_id)->toBe(TenancyScope::runAs(WFL_TENANT, fn () => Role::query()->where('system_role_code', 'rrhh')->value('id')));

    wflCall($this->boss, 'POST', '/approval-requests/'.$own->id.'/approve', [])->assertStatus(403);
    wflCall($this->hr, 'POST', '/approval-requests/'.$own->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');
});

test('con una delegación vigente la solicitud llega al delegado; también se puede delegar a mano', function (): void {
    TenancyScope::runAs(WFL_TENANT, fn () => DB::table('approval_delegations')->insert([
        'tenant_id' => WFL_TENANT, 'from_user_id' => $this->boss->id, 'to_user_id' => $this->director->id,
        'starts_on' => now()->subDay()->toDateString(), 'ends_on' => now()->addWeek()->toDateString(), 'reason' => 'Vacaciones',
    ]));
    wflFlow($this->admin, [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]);
    $req = wflStart($this->requester);

    $detail = wflCall($this->director, 'GET', '/approval-requests/'.$req->id);
    $detail->assertJsonPath('data.tasks.0.assignee_user_id', $this->director->id)->assertJsonPath('data.tasks.0.delegated_from_name', 'Beto Jefe');

    $manual = wflCall($this->director, 'POST', '/approval-requests/'.$req->id.'/delegate', ['to_user_id' => $this->hr->id, 'comment' => 'Lo ve RRHH']);
    $manual->assertOk()->assertJsonPath('data.tasks.0.assignee_user_id', $this->hr->id);
    $this->assertMatchesOpenApi($manual, 'post', '/approval-requests/{id}/delegate', 200);

    wflCall($this->hr, 'POST', '/approval-requests/'.$req->id.'/delegate', ['to_user_id' => $this->requester->id])->assertStatus(422);
    wflCall($this->hr, 'POST', '/approval-requests/'.$req->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');
});

test('una tarea vencida recibe un recordatorio y luego escala según la regla, y queda registrado', function (): void {
    wflFlow($this->admin, [['mode' => 'sequential', 'sla_hours' => 24, 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]], 'escalate_to' => ['kind' => 'user', 'user_id' => $this->director->id]]]);
    $req = wflStart($this->requester);
    $engine = app(ApprovalEngine::class);

    $this->travel(25)->hours();
    TenancyScope::runAs(WFL_TENANT, fn () => $engine->processOverdue());
    expect(wflOutbox($req->id, 'approval.reminder.v1'))->toBe(1)
        ->and(wflOutbox($req->id, 'approval.escalated.v1'))->toBe(0);

    $this->travel(25)->hours();
    TenancyScope::runAs(WFL_TENANT, fn () => $engine->processOverdue());
    expect(wflOutbox($req->id, 'approval.escalated.v1'))->toBe(1);

    $detail = wflCall($this->director, 'GET', '/approval-requests/'.$req->id);
    $detail->assertJsonPath('data.escalated', true)->assertJsonPath('data.tasks.0.assignee_user_id', $this->director->id);
    TenancyScope::runAs(WFL_TENANT, fn () => expect(DB::table('audit_events')->where('entity_id', $req->id)->where('action', 'approval_request.escalated')->count())->toBe(1));

    wflCall($this->director, 'POST', '/approval-requests/'.$req->id.'/approve', [])->assertOk()->assertJsonPath('data.status', 'approved');
});

test('el listado muestra a cada uno lo suyo; «asignadas a mí» filtra las pendientes', function (): void {
    wflFlow($this->admin, [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]);
    $req = wflStart($this->requester);

    $mine = wflCall($this->boss, 'GET', '/approval-requests?filter[assigned]=me');
    $mine->assertOk();
    expect(collect($mine->json('data'))->pluck('id')->all())->toBe([$req->id]);
    $this->assertMatchesOpenApi($mine, 'get', '/approval-requests', 200);

    expect(wflCall($this->requester, 'GET', '/approval-requests')->json('data.0.id'))->toBe($req->id);
    expect(wflCall($this->director, 'GET', '/approval-requests')->json('data'))->toBe([]);
    wflCall($this->director, 'GET', '/approval-requests/'.$req->id)->assertNotFound();
    expect(wflCall($this->hr, 'GET', '/approval-requests')->json('data.0.id'))->toBe($req->id);

    // Otra organización: 404.
    $foreign = wflUser('admin_empresa', 'Extraño', WFL_OTHER);
    wflCall($foreign, 'GET', '/approval-requests/'.$req->id)->assertNotFound();
});

test('sin permiso responde 403', function (): void {
    $r = wflCall($this->requester, 'POST', '/approval-flows', ['name' => 'X', 'subject_type' => 'vacation_request']);
    $r->assertStatus(403);
    $this->assertMatchesOpenApi($r, 'post', '/approval-flows', 403);
});
