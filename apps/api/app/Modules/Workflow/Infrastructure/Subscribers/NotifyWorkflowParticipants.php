<?php

namespace App\Modules\Workflow\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * Avisa a quien corresponde en cada paso de una aprobación o tarea (WFL → NOT). Los destinatarios
 * se calculan al procesar el evento (RN-NOT-01): si la tarea va a un rol, a quienes lo tienen hoy.
 * El consumo es idempotente por evento (ProcessedEventTracker): reprocesar no repite avisos.
 */
final class NotifyWorkflowParticipants implements DomainEventSubscriber
{
    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'workflow.notify_participants';
    }

    public function handles(): array
    {
        return [
            DomainEventTypes::APPROVAL_TASK_ASSIGNED,
            DomainEventTypes::APPROVAL_REMINDER,
            DomainEventTypes::APPROVAL_ESCALATED,
            DomainEventTypes::APPROVAL_APPROVED,
            DomainEventTypes::APPROVAL_REJECTED,
            DomainEventTypes::TASK_ASSIGNED,
            DomainEventTypes::TASK_OVERDUE,
        ];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array<string, mixed> $p */
            $p = $event->payload;
            match ($event->event_type) {
                DomainEventTypes::APPROVAL_TASK_ASSIGNED => $this->approvalTask($p, 'approval.pending'),
                DomainEventTypes::APPROVAL_REMINDER => $this->approvalTask($p, 'approval.reminder'),
                DomainEventTypes::APPROVAL_ESCALATED => $this->approvalTask($p, 'approval.escalated'),
                DomainEventTypes::APPROVAL_APPROVED, DomainEventTypes::APPROVAL_REJECTED => $this->decided($p, $event->event_type === DomainEventTypes::APPROVAL_APPROVED),
                DomainEventTypes::TASK_ASSIGNED => $this->hrTask($p, 'task.assigned'),
                DomainEventTypes::TASK_OVERDUE => $this->hrTask($p, 'task.overdue'),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function approvalTask(array $p, string $category): void
    {
        $task = DB::table('workflow_tasks as t')->join('workflow_instances as i', 'i.id', '=', 't.workflow_instance_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 't.workflow_step_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.started_by_user_id')
            ->where('t.id', (string) ($p['task_id'] ?? ''))
            ->first(['t.status', 't.assignee_user_id', 't.assignee_role_id', 'i.id as request_id', 'i.subject_type', 'i.subject_id', 'i.started_by_user_id', 'u.display_name as requester', 's.sort_order']);
        if ($task === null || $task->status !== 'pending') {
            return; // ya se resolvió: no se avisa de algo que ya no está pendiente.
        }
        $users = $task->assignee_user_id !== null ? [(string) $task->assignee_user_id] : ($task->assignee_role_id !== null ? $this->notifier->usersWithRole((string) $task->assignee_role_id) : []);
        // Nadie recibe avisos para aprobar lo suyo (RN-WFL-05).
        $users = array_values(array_filter($users, fn ($id) => $id !== $task->started_by_user_id));

        $this->notifier->notify($category, $users, [
            'requester' => (string) ($task->requester ?? ''), 'type' => (string) $task->subject_type, 'level' => (int) ($task->sort_order ?? 1),
            'approval_request_id' => (string) $task->request_id,
        ], (string) $task->subject_type, (string) $task->subject_id);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function decided(array $p, bool $approved): void
    {
        $request = DB::table('workflow_instances')->where('id', (string) ($p['approval_request_id'] ?? ''))->first(['id', 'subject_type', 'subject_id', 'started_by_user_id']);
        if ($request === null || $request->started_by_user_id === null) {
            return;
        }
        $this->notifier->notify($approved ? 'approval.approved' : 'approval.rejected', [(string) $request->started_by_user_id], [
            'type' => (string) $request->subject_type, 'reason' => (string) ($p['comment'] ?? ''), 'approval_request_id' => (string) $request->id,
        ], (string) $request->subject_type, (string) $request->subject_id);
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function hrTask(array $p, string $category): void
    {
        $task = DB::table('hr_tasks')->where('id', (string) ($p['task_id'] ?? ''))->whereNull('deleted_at')->first(['id', 'title', 'due_on', 'status', 'assignee_user_id', 'assignee_role_id']);
        if ($task === null || ! in_array($task->status, ['pending', 'in_progress'], true)) {
            return;
        }
        $users = $task->assignee_user_id !== null ? [(string) $task->assignee_user_id] : ($task->assignee_role_id !== null ? $this->notifier->usersWithRole((string) $task->assignee_role_id) : []);
        $this->notifier->notify($category, $users, ['title' => (string) $task->title, 'due_on' => (string) ($task->due_on ?? ''), 'task_id' => (string) $task->id], 'task', (string) $task->id);
    }
}
