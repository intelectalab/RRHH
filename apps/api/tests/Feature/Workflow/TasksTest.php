<?php

/**
 * WFL-8 · Tareas de RRHH.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Application\TaskService;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const TSK_TENANT = 'aaaaaaaa-8080-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => TSK_TENANT, 'slug' => 'tsk-org', 'name' => 'Grupo T', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [TSK_TENANT]);

    $this->hr = tskUser('rrhh', 'Hugo RRHH');
    $this->hr2 = tskUser('rrhh', 'Helena RRHH');
    $this->employee = tskUser('empleado', 'Elsa Empleada');
    $this->rrhhRole = TenancyScope::runAs(TSK_TENANT, fn () => Role::query()->where('system_role_code', 'rrhh')->value('id'));
});

afterEach(fn () => TenancyScope::clear());

function tskUser(string $systemRole, string $name): User
{
    return TenancyScope::runAs(TSK_TENANT, function () use ($systemRole, $name): User {
        $user = User::factory()->create(['display_name' => $name]);
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->value('id')]);

        return $user;
    });
}

function tskCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')
        ->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])
        ->json($method, '/api/v1'.$path, $body);
}

test('una tarea se asigna a una persona o a un rol y se completa por acción', function (): void {
    $toPerson = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Entregar fotocheck', 'assignee_user_id' => $this->employee->id, 'priority' => 'high', 'due_on' => now()->addDays(2)->toDateString()]);
    $toPerson->assertCreated()->assertJsonPath('data.assignee_name', 'Elsa Empleada')->assertJsonPath('data.status', 'pending');
    $this->assertMatchesOpenApi($toPerson, 'post', '/tasks', 201);

    $toRole = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Validar legajo', 'assignee_role_id' => $this->rrhhRole]);
    $toRole->assertCreated()->assertJsonPath('data.assignee_user_id', null)->assertJsonPath('data.assignee_role_id', $this->rrhhRole);

    // Cualquiera con el rol la ve como suya y la puede completar.
    $mine = tskCall($this->hr2, 'GET', '/tasks?filter[assigned]=me');
    $mine->assertOk();
    expect(collect($mine->json('data'))->pluck('id')->all())->toBe([$toRole->json('data.id')]);
    $this->assertMatchesOpenApi($mine, 'get', '/tasks', 200);

    $done = tskCall($this->hr2, 'POST', '/tasks/'.$toRole->json('data.id').'/complete', []);
    $done->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.allowed_actions', []);
    $this->assertMatchesOpenApi($done, 'post', '/tasks/{id}/complete', 200);

    $again = tskCall($this->hr2, 'POST', '/tasks/'.$toRole->json('data.id').'/complete', []);
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/tasks/{id}/complete', 409);

    TenancyScope::runAs(TSK_TENANT, function () use ($toPerson, $toRole): void {
        expect(DB::table('outbox_events')->where('aggregate_id', $toPerson->json('data.id'))->where('event_type', 'task.assigned.v1')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('entity_id', $toRole->json('data.id'))->where('action', 'task.complete')->count())->toBe(1);
    });
});

test('sin responsable, con responsable inexistente o sin título se rechaza', function (): void {
    $r = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Sin responsable']);
    $r->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    $this->assertMatchesOpenApi($r, 'post', '/tasks', 422);
    tskCall($this->hr, 'POST', '/tasks', ['title' => 'X', 'assignee_user_id' => (string) Str::uuid()])->assertStatus(422);
    tskCall($this->hr, 'POST', '/tasks', ['assignee_user_id' => $this->employee->id])->assertStatus(422);
});

test('editar y reasignar; una tarea cancelada no se edita', function (): void {
    $id = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Inducción', 'assignee_user_id' => $this->employee->id])->json('data.id');

    $patch = tskCall($this->hr, 'PATCH', '/tasks/'.$id, ['assignee_user_id' => $this->hr2->id, 'priority' => 'urgent']);
    $patch->assertOk()->assertJsonPath('data.assignee_name', 'Helena RRHH')->assertJsonPath('data.priority', 'urgent');
    $this->assertMatchesOpenApi($patch, 'patch', '/tasks/{id}', 200);

    $cancel = tskCall($this->hr, 'POST', '/tasks/'.$id.'/cancel', ['reason' => 'Duplicada']);
    $cancel->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->assertMatchesOpenApi($cancel, 'post', '/tasks/{id}/cancel', 200);
    tskCall($this->hr, 'PATCH', '/tasks/'.$id, ['title' => 'Otra'])->assertStatus(409);
});

test('el colaborador solo ve sus tareas y no puede crearlas', function (): void {
    $own = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Firmar política', 'assignee_user_id' => $this->employee->id])->json('data.id');
    $other = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Revisar planilla', 'assignee_user_id' => $this->hr2->id])->json('data.id');

    expect(collect(tskCall($this->employee, 'GET', '/tasks')->json('data'))->pluck('id')->all())->toBe([$own]);
    $show = tskCall($this->employee, 'GET', '/tasks/'.$own);
    $show->assertOk()->assertJsonPath('data.mine', true);
    $this->assertMatchesOpenApi($show, 'get', '/tasks/{id}', 200);
    tskCall($this->employee, 'GET', '/tasks/'.$other)->assertNotFound();

    $forbidden = tskCall($this->employee, 'POST', '/tasks', ['title' => 'X', 'assignee_user_id' => $this->employee->id]);
    $forbidden->assertStatus(403);
    $this->assertMatchesOpenApi($forbidden, 'post', '/tasks', 403);

    tskCall($this->employee, 'POST', '/tasks/'.$own.'/complete', [])->assertOk();
});

test('las tareas vencidas se resaltan y se avisan una sola vez', function (): void {
    $id = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Renovar SCTR', 'assignee_user_id' => $this->hr2->id, 'due_on' => now()->addDay()->toDateString()])->json('data.id');
    tskCall($this->hr, 'GET', '/tasks/'.$id)->assertJsonPath('data.overdue', false);

    $this->travel(3)->days();
    tskCall($this->hr, 'GET', '/tasks/'.$id)->assertJsonPath('data.overdue', true);
    expect(collect(tskCall($this->hr, 'GET', '/tasks?filter[overdue]=1')->json('data'))->pluck('id')->all())->toBe([$id]);

    $service = app(TaskService::class);
    TenancyScope::runAs(TSK_TENANT, fn () => $service->notifyOverdue());
    TenancyScope::runAs(TSK_TENANT, fn () => $service->notifyOverdue());
    TenancyScope::runAs(TSK_TENANT, fn () => expect(DB::table('outbox_events')->where('aggregate_id', $id)->where('event_type', 'task.overdue.v1')->count())->toBe(1));
});

test('UI-12 · empezar una tarea la pasa a en curso (tablero kanban); otra vez responde 409', function (): void {
    $task = tskCall($this->hr, 'POST', '/tasks', ['title' => 'Revisar contratos', 'assignee_user_id' => $this->hr2->id])->assertCreated();
    expect($task->json('data.allowed_actions'))->toContain('start');

    $started = tskCall($this->hr2, 'POST', '/tasks/'.$task->json('data.id').'/start', []);
    $started->assertOk()->assertJsonPath('data.status', 'in_progress');
    expect($started->json('data.allowed_actions'))->not->toContain('start')->toContain('complete');
    $this->assertMatchesOpenApi($started, 'post', '/tasks/{id}/start', 200);

    $again = tskCall($this->hr2, 'POST', '/tasks/'.$task->json('data.id').'/start', []);
    $again->assertStatus(409)->assertJsonPath('error.code', 'invalid_transition');
    $this->assertMatchesOpenApi($again, 'post', '/tasks/{id}/start', 409);

    $denied = tskCall($this->employee, 'POST', '/tasks/'.$task->json('data.id').'/start', []);
    expect($denied->status())->toBeIn([403, 404]);

    TenancyScope::runAs(TSK_TENANT, fn () => expect(DB::table('audit_events')->where('entity_id', $task->json('data.id'))->where('action', 'task.start')->count())->toBe(1));
});
