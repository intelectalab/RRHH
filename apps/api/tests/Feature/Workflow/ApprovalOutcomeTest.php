<?php

/**
 * WFL-4 · Aprobar desde el centro de aprobaciones actualiza de inmediato el módulo de origen: el módulo
 * dueño del tipo aplica el resultado en la misma transacción (ApprovalOutcomeHandler); si no puede
 * aplicarlo, la decisión tampoco queda registrada.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Workflow\Application\ApprovalEngine;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Workflow\ApprovalOutcomeHandler;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

const AOC_TENANT = 'aaaaaaaa-7171-4000-8000-00000000000d';

/** Módulo de origen de prueba: guarda el estado de cada trámite en memoria, como lo haría su tabla. */
final class FakePermissionModule implements ApprovalOutcomeHandler
{
    /** @var array<string, string> */
    public array $status = [];

    public bool $refuse = false;

    public function subjectType(): string
    {
        return 'permission_request';
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        if ($this->refuse) {
            throw new BusinessRuleViolatedException('balance_exceeded', 'El permiso ya no tiene saldo.');
        }
        // Lo que el módulo escribe en su tabla debe verse en la misma transacción que la aprobación.
        expect(DB::transactionLevel())->toBeGreaterThan(0);
        $this->status[$subjectId] = 'approved';
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->status[$subjectId] = 'rejected:'.$reason;
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->status[$subjectId] = 'cancelled';
    }
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => AOC_TENANT, 'slug' => 'aoc-org', 'name' => 'Grupo AOC', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [AOC_TENANT]);

    $this->admin = aocUser('admin_empresa', 'Ana Admin');
    $this->requester = aocUser('empleado', 'Rita Solicitante');
    $this->boss = aocUser('manager', 'Beto Jefe');
    $this->module = new FakePermissionModule;
    app(ApprovalOutcomeRegistry::class)->register($this->module);

    $flow = aocCall($this->admin, 'POST', '/approval-flows', ['name' => 'Permisos', 'subject_type' => 'permission_request', 'steps' => ['levels' => [['mode' => 'sequential', 'approvers' => [['kind' => 'user', 'user_id' => $this->boss->id]]]]]]);
    $flow->assertCreated();
    aocCall($this->admin, 'POST', '/approval-flows/'.$flow->json('data.id').'/publish')->assertOk();
});

afterEach(fn () => TenancyScope::clear());

function aocUser(string $systemRole, string $name): User
{
    return TenancyScope::runAs(AOC_TENANT, function () use ($systemRole, $name): User {
        DB::table('roles')->where('system_role_code', $systemRole)->update(['mfa_required' => false]);
        $user = User::factory()->create(['display_name' => $name]);
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', $systemRole)->value('id')]);

        return $user;
    });
}

function aocCall(User $user, string $method, string $path, array $body = [])
{
    TenancyScope::clear();
    app('auth')->forgetGuards();

    return test()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $user->tenant_id, 'Idempotency-Key' => (string) Str::uuid()])->json($method, '/api/v1'.$path, $body);
}

function aocStart(User $requester, string $subjectId): ApprovalRequest
{
    return TenancyScope::runAs(AOC_TENANT, fn () => app(ApprovalEngine::class)->start('permission_request', $subjectId, $requester->id));
}

test('aprobar desde el centro actualiza el módulo de origen antes de responder', function (): void {
    $subject = (string) Str::uuid();
    $req = aocStart($this->requester, $subject);
    expect($this->module->status)->toBe([]);

    $r = aocCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/approve');
    $r->assertOk()->assertJsonPath('data.status', 'approved');
    $this->assertMatchesOpenApi($r, 'post', '/approval-requests/{id}/approve', 200);
    expect($this->module->status[$subject])->toBe('approved');
});

test('rechazar y cancelar también llegan al módulo de origen con su motivo', function (): void {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $ra = aocStart($this->requester, $a);
    $rb = aocStart($this->requester, $b);

    aocCall($this->boss, 'POST', '/approval-requests/'.$ra->id.'/reject', ['comment' => 'Sin cobertura'])->assertOk();
    TenancyScope::runAs(AOC_TENANT, fn () => app(ApprovalEngine::class)->cancel($rb, 'El colaborador desistió'));

    expect($this->module->status[$a])->toBe('rejected:Sin cobertura')
        ->and($this->module->status[$b])->toBe('cancelled');
});

test('si el módulo no puede aplicar la aprobación, la decisión no queda registrada', function (): void {
    $this->module->refuse = true;
    $req = aocStart($this->requester, (string) Str::uuid());

    $r = aocCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/approve');
    $r->assertStatus(422)->assertJsonPath('error.code', 'business_rule_violated');
    $this->assertMatchesOpenApi($r, 'post', '/approval-requests/{id}/approve', 422);

    TenancyScope::runAs(AOC_TENANT, function () use ($req): void {
        expect(ApprovalRequest::query()->find($req->id)->status)->not->toBe('approved')
            ->and(DB::table('workflow_decisions')->join('workflow_tasks as t', 't.id', '=', 'workflow_decisions.workflow_task_id')->where('t.workflow_instance_id', $req->id)->count())->toBe(0)
            ->and(DB::table('outbox_events')->where('aggregate_id', $req->id)->where('event_type', 'approval.approved.v1')->count())->toBe(0);
    });

    // Resuelto el problema en el módulo, la misma tarea se puede aprobar.
    $this->module->refuse = false;
    aocCall($this->boss, 'POST', '/approval-requests/'.$req->id.'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
});

test('la aprobación masiva aplica cada resultado en su módulo', function (): void {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $ra = aocStart($this->requester, $a);
    $rb = aocStart($this->requester, $b);

    aocCall($this->boss, 'POST', '/approval-requests/bulk-approve', ['ids' => [$ra->id, $rb->id]])->assertOk();
    expect($this->module->status)->toBe([$a => 'approved', $b => 'approved']);
});
