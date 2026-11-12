<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\ApprovalRequestStateMachine;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Modules\Workflow\Infrastructure\Models\WorkflowDecision;
use App\Modules\Workflow\Infrastructure\Models\WorkflowStep;
use App\Modules\Workflow\Infrastructure\Models\WorkflowTask;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Workflow\ApprovalOutcomeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WFL-2 · Motor de aprobaciones de N niveles (secuencial, todos en paralelo o cualquiera en paralelo).
 *
 * Un módulo pide una aprobación con start(); el motor asigna aprobadores, recibe decisiones
 * (idempotentes y auditadas) y devuelve el resultado al módulo por el outbox una sola vez
 * (approval.approved / approval.rejected).
 */
final class ApprovalEngine
{
    public function __construct(
        private readonly ApprovalFlowService $flows,
        private readonly ApproverResolver $resolver,
        private readonly ApprovalRequestStateMachine $machine,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
        private readonly ApprovalOutcomeRegistry $outcomes,
    ) {}

    /**
     * @param  array<string, mixed>  $context  datos del trámite para condiciones (empresa, monto, días…)
     */
    public function start(string $subjectType, string $subjectId, string $requesterUserId, array $context = []): ApprovalRequest
    {
        $version = $this->flows->select($subjectType, $context);
        if ($version === null) {
            throw new BusinessRuleViolatedException('RN-WFL-01', (string) trans('workflow.no_flow', ['type' => $subjectType]));
        }

        return DB::transaction(function () use ($version, $subjectType, $subjectId, $requesterUserId, $context): ApprovalRequest {
            $request = ApprovalRequest::create([
                'workflow_definition_version_id' => $version->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'status' => 'pending',
                'started_by_user_id' => $requesterUserId,
                'context' => $context,
            ]);

            $this->machine->getNextState('pending', 'assign_steps');
            $request->forceFill(['status' => 'running'])->save();
            $this->enterLevel($request, 1);

            $this->event(DomainEventTypes::APPROVAL_REQUESTED, $request);
            $this->audit->record('approval_request.started', 'approval_request', $request->id, null, ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'flow_version_id' => $version->id]);

            return $request->refresh();
        });
    }

    /**
     * Aprobar o rechazar. Idempotente: repetir la misma decisión del mismo usuario no crea otra.
     */
    public function decide(ApprovalRequest $request, string $userId, string $decision, ?string $comment): ApprovalRequest
    {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new AyniException('validation_failed', 422);
        }
        if ($decision === 'reject' && trim((string) $comment) === '') {
            throw ValidationException::withMessages(['comment' => [(string) trans('workflow.reject_reason_required')]]); // RN-WFL-04
        }

        return DB::transaction(function () use ($request, $userId, $decision, $comment): ApprovalRequest {
            /** @var ApprovalRequest $request */
            $request = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            // Idempotencia: el mismo usuario ya tomó esta decisión y no le queda otra tarea pendiente
            // (doble clic, reintento sin Idempotency-Key): se devuelve el estado actual sin crear otra.
            if ($this->lastDecisionBy($request, $userId) === $decision && $this->pendingTaskFor($request, $userId) === null) {
                return $request;
            }

            $this->machine->getNextState((string) $request->status, $decision === 'reject' ? 'reject' : 'approve_step');

            if ($userId === $request->started_by_user_id) {
                throw new AyniException('forbidden', 403, (string) trans('workflow.self_approval'));
            }
            $task = $this->pendingTaskFor($request, $userId);
            if ($task === null) {
                throw new AyniException('forbidden', 403, (string) trans('workflow.not_assignee'));
            }

            $task->forceFill(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'completed_at' => now(), 'assignee_user_id' => $task->assignee_user_id ?? $userId])->save();
            WorkflowDecision::create([
                'workflow_task_id' => $task->id,
                'decided_by_user_id' => $userId,
                'decision' => $decision,
                'comment' => $comment,
                'on_behalf_of_user_id' => $task->delegated_from_user_id,
                'decided_at' => now(),
                'ip' => request()->ip(),
            ]);
            $this->audit->record("approval_request.{$decision}d", 'approval_request', $request->id, ['status' => $request->status], ['task_id' => $task->id, 'decision' => $decision], $comment);

            if ($decision === 'reject') {
                $this->cancelPending($request, 'cancelled');
                $this->finish($request, 'rejected', $comment);

                return $request->refresh();
            }

            if ($this->levelComplete($request)) {
                $this->cancelPending($request, 'skipped');
                $level = $this->levelNumber($request);
                if (! $this->enterLevel($request, $level + 1)) {
                    $this->finish($request, 'approved', null);
                } else {
                    $request->forceFill(['status' => 'running'])->save();
                    $this->event(DomainEventTypes::APPROVAL_STEP_APPROVED, $request, ['level' => $level]);
                }
            }

            return $request->refresh();
        });
    }

    /**
     * Reasignar la propia tarea a otra persona (decisión «delegate»).
     */
    public function delegate(ApprovalRequest $request, string $userId, string $toUserId, ?string $comment): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $userId, $toUserId, $comment): ApprovalRequest {
            $this->machine->getNextState((string) $request->status, 'delegate');
            $task = $this->pendingTaskFor($request, $userId);
            if ($task === null) {
                throw new AyniException('forbidden', 403, (string) trans('workflow.not_assignee'));
            }
            if ($toUserId === $request->started_by_user_id || $toUserId === $userId || ! $this->resolver->isActiveUser($toUserId)) {
                throw ValidationException::withMessages(['to_user_id' => [(string) trans('workflow.invalid_delegate')]]);
            }

            WorkflowDecision::create(['workflow_task_id' => $task->id, 'decided_by_user_id' => $userId, 'decision' => 'delegate', 'comment' => $comment, 'decided_at' => now(), 'ip' => request()->ip()]);
            $task->forceFill(['assignee_user_id' => $toUserId, 'assignee_role_id' => null, 'delegated_from_user_id' => $userId])->save();
            $this->event(DomainEventTypes::APPROVAL_TASK_ASSIGNED, $request, ['task_id' => $task->id]);
            $this->audit->record('approval_request.delegated', 'approval_request', $request->id, ['assignee' => $userId], ['assignee' => $toUserId], $comment);

            return $request->refresh();
        });
    }

    /**
     * RN-WFL-13: el solicitante o el módulo de origen cancela antes de la decisión final.
     */
    public function cancel(ApprovalRequest $request, ?string $reason): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $reason): ApprovalRequest {
            $this->machine->getNextState((string) $request->status, 'cancel');
            $this->cancelPending($request, 'cancelled');
            $request->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
            $this->outcomes->for((string) $request->subject_type)?->cancelled((string) $request->subject_id, $request->id, $reason);
            $this->event(DomainEventTypes::APPROVAL_CANCELLED, $request, ['reason' => $reason]);
            $this->audit->record('approval_request.cancelled', 'approval_request', $request->id, null, ['status' => 'cancelled'], $reason);

            return $request->refresh();
        });
    }

    /**
     * RN-WFL-06: primer vencimiento → recordatorio; segundo → escalamiento al destino del nivel,
     * al jefe del aprobador o a RRHH.
     */
    public function processOverdue(): int
    {
        $count = 0;
        $tasks = WorkflowTask::query()->where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<', now())->get();
        foreach ($tasks as $task) {
            DB::transaction(function () use ($task, &$count): void {
                /** @var ApprovalRequest $request */
                $request = ApprovalRequest::query()->findOrFail($task->workflow_instance_id);
                /** @var WorkflowStep $step */
                $step = WorkflowStep::query()->findOrFail($task->workflow_step_id);
                $sla = max(1, (int) ($step->sla_hours ?? 24));

                // Un recordatorio por ciclo: desde la creación de la tarea o su último escalamiento.
                $events = DB::table('outbox_events')->where('aggregate_id', $request->id)->whereRaw("payload->>'task_id' = ?", [$task->id]);
                $since = (clone $events)->where('event_type', DomainEventTypes::APPROVAL_ESCALATED)->max('occurred_at') ?? $task->created_at;
                $reminded = (clone $events)->where('event_type', DomainEventTypes::APPROVAL_REMINDER)->where('occurred_at', '>', $since)->exists();
                if (! $reminded) {
                    $task->forceFill(['due_at' => now()->addHours($sla)])->save();
                    $this->event(DomainEventTypes::APPROVAL_REMINDER, $request, ['task_id' => $task->id, 'assignee_user_id' => $task->assignee_user_id]);
                    $count++;

                    return;
                }

                $target = $this->escalationTarget($step, $task);
                $from = ['assignee_user_id' => $task->assignee_user_id, 'assignee_role_id' => $task->assignee_role_id];
                $task->forceFill(['assignee_user_id' => $target['user_id'], 'assignee_role_id' => $target['role_id'], 'due_at' => now()->addHours($sla)])->save();
                $request->forceFill(['status' => $this->machine->getNextState((string) $request->status, 'escalate')])->save();
                $this->event(DomainEventTypes::APPROVAL_ESCALATED, $request, ['task_id' => $task->id] + $target);
                $this->audit->record('approval_request.escalated', 'approval_request', $request->id, $from, $target, (string) trans('workflow.escalated_reason'));
                $count++;
            });
        }

        return $count;
    }

    /**
     * Crea las tareas de un nivel. Devuelve false si el nivel no existe (ya no hay más niveles).
     * En modo secuencial solo se crea la tarea del primer aprobador; las siguientes nacen al aprobarse la anterior.
     */
    private function enterLevel(ApprovalRequest $request, int $level): bool
    {
        $step = $this->stepAt($request, $level);
        if ($step === null) {
            return false;
        }

        $resolved = $this->resolveLevel($request, $step);
        if ($resolved === []) {
            // Sin aprobador válido en el nivel: pasa al siguiente; si no hay, va a RRHH (RN-WFL-05).
            if ($this->stepAt($request, $level + 1) !== null) {
                return $this->enterLevel($request, $level + 1);
            }
            $resolved = [['user_id' => null, 'role_id' => $this->resolver->fallbackRoleId(), 'delegated_from' => null]];
        }

        $request->forceFill(['current_step_id' => $step->id])->save();
        $targets = (string) $step->approval_mode === 'sequential' ? [$resolved[0]] : $resolved;
        foreach ($targets as $i => $r) {
            $this->createTask($request, $step, $r, $i + 1);
        }

        return true;
    }

    /**
     * Aprobadores concretos de un nivel, ya con delegaciones y sin el solicitante.
     *
     * @return array<int, array{user_id: string|null, role_id: string|null, delegated_from: string|null}>
     */
    private function resolveLevel(ApprovalRequest $request, WorkflowStep $step): array
    {
        $requester = (string) $request->started_by_user_id;
        $resolved = [];
        foreach ((array) (($step->action_config ?? [])['approvers'] ?? []) as $approver) {
            $r = $this->resolver->resolve((array) $approver, $requester);
            if ($r === null) {
                continue;
            }
            $from = null;
            if ($r['user_id'] !== null) {
                // RN-WFL-07: delegación vigente; aprobador inactivo → su jefe.
                $d = $this->resolver->applyDelegation($r['user_id'], (string) $request->subject_type);
                $user = $d['user_id'];
                $from = $d['delegated_from'];
                if (! $this->resolver->isActiveUser($user)) {
                    $from = $user;
                    $user = $this->resolver->managerOf($user);
                }
                // RN-WFL-05: nadie aprueba lo suyo.
                if ($user === null || $user === $requester) {
                    continue;
                }
                $r = ['user_id' => $user, 'role_id' => null];
            }
            $key = ($r['user_id'] ?? '').'|'.($r['role_id'] ?? '');
            $resolved[$key] = $r + ['delegated_from' => $from];
        }

        return array_values($resolved);
    }

    /**
     * @param  array{user_id: string|null, role_id: string|null, delegated_from: string|null}  $r
     */
    private function createTask(ApprovalRequest $request, WorkflowStep $step, array $r, int $sequence): void
    {
        $task = WorkflowTask::create([
            'workflow_instance_id' => $request->id,
            'workflow_step_id' => $step->id,
            'assignee_user_id' => $r['user_id'],
            'assignee_role_id' => $r['role_id'],
            'delegated_from_user_id' => $r['delegated_from'],
            'status' => 'pending',
            'due_at' => $step->sla_hours !== null ? now()->addHours((int) $step->sla_hours) : null,
            'sequence' => $sequence,
        ]);
        $this->event(DomainEventTypes::APPROVAL_TASK_ASSIGNED, $request, ['task_id' => $task->id]);
    }

    private function stepAt(ApprovalRequest $request, int $level): ?WorkflowStep
    {
        /** @var WorkflowStep|null */
        return WorkflowStep::query()
            ->where('workflow_definition_version_id', $request->workflow_definition_version_id)
            ->where('kind', 'approval')
            ->where('sort_order', $level)
            ->first();
    }

    /**
     * Secuencial: tras una aprobación, crea la tarea del siguiente aprobador. Devuelve false si no queda ninguno.
     */
    private function advanceSequential(ApprovalRequest $request, WorkflowStep $step): bool
    {
        // Avanza por posición: una tarea delegada o escalada cuenta como el turno de su aprobador original.
        $approved = WorkflowTask::query()->where('workflow_instance_id', $request->id)->where('workflow_step_id', $step->id)->where('status', 'approved')->count();
        $resolved = $this->resolveLevel($request, $step);
        if ($approved >= count($resolved)) {
            return false;
        }
        $this->createTask($request, $step, $resolved[$approved], $approved + 1);

        return true;
    }

    private function levelComplete(ApprovalRequest $request): bool
    {
        /** @var WorkflowStep $step */
        $step = WorkflowStep::query()->findOrFail($request->current_step_id);
        $tasks = WorkflowTask::query()->where('workflow_instance_id', $request->id)->where('workflow_step_id', $step->id)->get();

        return match ((string) $step->approval_mode) {
            'parallel_any' => $tasks->where('status', 'approved')->count() >= max(1, (int) ((($step->action_config ?? [])['min_approvals'] ?? 1))),
            'sequential' => $tasks->where('status', 'pending')->isEmpty() && ! $this->advanceSequential($request, $step),
            default => $tasks->where('status', 'pending')->isEmpty(),
        };
    }

    private function levelNumber(ApprovalRequest $request): int
    {
        return (int) WorkflowStep::query()->whereKey($request->current_step_id)->value('sort_order');
    }

    private function cancelPending(ApprovalRequest $request, string $as): void
    {
        WorkflowTask::query()->where('workflow_instance_id', $request->id)->where('status', 'pending')->update(['status' => $as, 'completed_at' => now()]);
    }

    private function finish(ApprovalRequest $request, string $status, ?string $comment): void
    {
        $request->forceFill(['status' => $status, 'finished_at' => now()])->save();
        // WFL-4: el módulo dueño aplica el resultado en la misma transacción (actualiza de inmediato su registro).
        $handler = $this->outcomes->for((string) $request->subject_type);
        if ($status === 'approved') {
            $handler?->approved((string) $request->subject_id, $request->id);
        } else {
            $handler?->rejected((string) $request->subject_id, $request->id, $comment);
        }
        // Además recibe el resultado una sola vez por el outbox (otros consumidores, registro de eventos procesados).
        $this->event($status === 'approved' ? DomainEventTypes::APPROVAL_APPROVED : DomainEventTypes::APPROVAL_REJECTED, $request, ['comment' => $comment]);
    }

    private function pendingTaskFor(ApprovalRequest $request, string $userId): ?WorkflowTask
    {
        $tasks = WorkflowTask::query()->where('workflow_instance_id', $request->id)->where('workflow_step_id', $request->current_step_id)
            ->where('status', 'pending')->orderBy('sequence')->get();

        foreach ($tasks as $task) {
            if ($task->assignee_user_id === $userId || ($task->assignee_user_id === null && $task->assignee_role_id !== null && $this->resolver->userHasRole($userId, (string) $task->assignee_role_id))) {
                return $task;
            }
        }

        return null;
    }

    private function lastDecisionBy(ApprovalRequest $request, string $userId): ?string
    {
        $d = WorkflowDecision::query()->join('workflow_tasks as t', 't.id', '=', 'workflow_decisions.workflow_task_id')
            ->where('t.workflow_instance_id', $request->id)->where('workflow_decisions.decided_by_user_id', $userId)
            ->orderByDesc('workflow_decisions.decided_at')->value('workflow_decisions.decision');

        return $d === null ? null : (string) $d;
    }

    /**
     * @return array{user_id: string|null, role_id: string|null}
     */
    private function escalationTarget(WorkflowStep $step, WorkflowTask $task): array
    {
        $config = (array) ($step->escalation_config ?? []);
        if (($config['kind'] ?? null) === 'user' && isset($config['user_id'])) {
            return ['user_id' => (string) $config['user_id'], 'role_id' => null];
        }
        if (($config['kind'] ?? null) === 'role' && isset($config['role_id'])) {
            return ['user_id' => null, 'role_id' => (string) $config['role_id']];
        }
        if ($task->assignee_user_id !== null && ($manager = $this->resolver->managerOf((string) $task->assignee_user_id)) !== null) {
            return ['user_id' => $manager, 'role_id' => null];
        }

        return ['user_id' => null, 'role_id' => $this->resolver->fallbackRoleId()];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function event(string $type, ApprovalRequest $request, array $extra = []): void
    {
        $this->outbox->record($type, 'approval_request', $request->id, [
            'approval_request_id' => $request->id,
            'subject_type' => $request->subject_type,
            'subject_id' => $request->subject_id,
            'status' => $request->status,
        ] + $extra);
    }
}
