<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\TaskStateMachine;
use App\Modules\Workflow\Infrastructure\Models\Task;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WFL-8 · Tareas de RRHH: asignadas a una persona o a un rol, con vencimiento, prioridad y
 * vínculo al registro relacionado. Las crean personas o reglas (WFL-5).
 */
final class TaskService
{
    public function __construct(
        private readonly TaskStateMachine $machine,
        private readonly ApproverResolver $resolver,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Task $task, ?string $viewerId = null): array
    {
        $assignee = $task->assignee_user_id !== null
            ? DB::table('users')->where('id', $task->assignee_user_id)->value('display_name')
            : ($task->assignee_role_id !== null ? DB::table('roles')->where('id', $task->assignee_role_id)->value('name') : null);
        $creator = $task->created_by_user_id !== null ? DB::table('users')->where('id', $task->created_by_user_id)->value('display_name') : null;
        $open = in_array($task->status, ['pending', 'in_progress'], true);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'assignee_user_id' => $task->assignee_user_id,
            'assignee_role_id' => $task->assignee_role_id,
            'assignee_name' => $assignee === null ? null : (string) $assignee,
            'created_by_name' => $creator === null ? null : (string) $creator,
            'due_on' => $task->due_on?->toDateString(),
            'priority' => $task->priority,
            'status' => $task->status,
            'overdue' => $open && $task->due_on !== null && $task->due_on->lt(today()),
            'related_type' => $task->subject_type ?? ($task->related_employment_id !== null ? 'employment' : null),
            'related_id' => $task->subject_id ?? $task->related_employment_id,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'allowed_actions' => array_values(array_intersect($this->machine->allowedActions($task->status), ['start', 'complete', 'cancel'])),
            'mine' => $viewerId !== null && $this->isAssignee($task, $viewerId),
            'version' => $task->lock_version,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?string $creatorId): Task
    {
        $this->validateAssignee($input);

        return DB::transaction(function () use ($input, $creatorId): Task {
            $task = Task::create($this->attributes($input) + ['created_by_user_id' => $creatorId, 'status' => 'pending']);
            $this->audit->record('task.created', 'task', $task->id, null, $this->present($task->refresh()));
            $this->assigned($task);

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Task $task, array $input): Task
    {
        if (! in_array($task->status, ['pending', 'in_progress'], true)) {
            throw new InvalidTransitionException($task->status, []);
        }
        $this->validateAssignee($input + ['assignee_user_id' => $task->assignee_user_id, 'assignee_role_id' => $task->assignee_role_id]);

        return DB::transaction(function () use ($task, $input): Task {
            $before = $this->present($task);
            $reassigned = array_key_exists('assignee_user_id', $input) || array_key_exists('assignee_role_id', $input);
            $task->fill($this->attributes($input));
            if ($task->isDirty()) {
                $task->save();
                $this->audit->record('task.updated', 'task', $task->id, $before, $this->present($task->refresh()));
                if ($reassigned && ($before['assignee_user_id'] !== $task->assignee_user_id || $before['assignee_role_id'] !== $task->assignee_role_id)) {
                    $this->assigned($task);
                }
            }

            return $task;
        });
    }

    public function transition(Task $task, string $action, ?string $reason): Task
    {
        $next = $this->machine->getNextState($task->status, $action);

        return DB::transaction(function () use ($task, $action, $next, $reason): Task {
            $before = ['status' => $task->status];
            $task->forceFill(['status' => $next, 'completed_at' => $next === 'done' ? now() : $task->completed_at])->save();
            $this->audit->record("task.{$action}", 'task', $task->id, $before, ['status' => $next], $reason);

            return $task;
        });
    }

    /**
     * Avisa una sola vez por cada tarea abierta que pasó su fecha de vencimiento.
     */
    public function notifyOverdue(): int
    {
        $count = 0;
        Task::query()->whereIn('status', ['pending', 'in_progress'])->whereNotNull('due_on')->where('due_on', '<', today()->toDateString())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('outbox_events')->whereColumn('outbox_events.aggregate_id', 'hr_tasks.id')->where('event_type', DomainEventTypes::TASK_OVERDUE))
            ->orderBy('due_on')->each(function (Task $task) use (&$count): void {
                $this->outbox->record(DomainEventTypes::TASK_OVERDUE, 'task', $task->id, [
                    'task_id' => $task->id, 'due_on' => $task->due_on?->toDateString(),
                    'assignee_user_id' => $task->assignee_user_id, 'assignee_role_id' => $task->assignee_role_id,
                ]);
                $count++;
            });

        return $count;
    }

    /**
     * Alcance: organización/empresa → todas; equipo → además las de su equipo; resto → las suyas
     * (asignadas a él o a uno de sus roles, o creadas por él).
     *
     * @param  Builder<Task>  $query
     * @param  array{0: string, 1: array<int, mixed>}|null  $teamSql
     */
    public function applyScope(Builder $query, string $userId, ?string $scope, bool $onlyMine, ?array $teamSql = null): void
    {
        if (in_array($scope, ['organization', 'legal_entity'], true) && ! $onlyMine) {
            return;
        }
        $roles = $this->roleIdsOf($userId);
        $query->where(function ($w) use ($userId, $roles, $onlyMine, $teamSql): void {
            $w->where('assignee_user_id', $userId)->orWhere(fn ($r) => $r->whereNull('assignee_user_id')->whereIn('assignee_role_id', $roles === [] ? [''] : $roles));
            if (! $onlyMine) {
                $w->orWhere('created_by_user_id', $userId);
                if ($teamSql !== null) {
                    $w->orWhereRaw('related_employment_id IN ('.$teamSql[0].')', $teamSql[1]);
                }
            }
        });
    }

    public function isAssignee(Task $task, string $userId): bool
    {
        return $task->assignee_user_id === $userId
            || ($task->assignee_user_id === null && $task->assignee_role_id !== null && $this->resolver->userHasRole($userId, $task->assignee_role_id));
    }

    /**
     * @return array<int, string>
     */
    public function roleIdsOf(string $userId): array
    {
        $today = now()->toDateString();

        return DB::table('user_roles')->where('user_id', $userId)
            ->where(fn ($v) => $v->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
            ->where(fn ($v) => $v->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->pluck('role_id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function attributes(array $input): array
    {
        $map = ['title' => 'title', 'description' => 'description', 'due_on' => 'due_on', 'priority' => 'priority',
            'assignee_user_id' => 'assignee_user_id', 'assignee_role_id' => 'assignee_role_id', 'related_type' => 'subject_type', 'related_id' => 'subject_id'];
        $out = [];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $input)) {
                $out[$col] = $input[$in];
            }
        }
        if (($out['subject_type'] ?? null) === 'employment') {
            $out['related_employment_id'] = $out['subject_id'] ?? null;
            $out['subject_type'] = null;
            $out['subject_id'] = null;
        }
        // Una tarea va a una persona o a un rol, no a ambos.
        if (! empty($out['assignee_user_id'])) {
            $out['assignee_role_id'] = null;
        } elseif (! empty($out['assignee_role_id'])) {
            $out['assignee_user_id'] = null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateAssignee(array $input): void
    {
        $user = $input['assignee_user_id'] ?? null;
        $role = $input['assignee_role_id'] ?? null;
        $errors = [];
        if (empty($user) && empty($role)) {
            $errors['assignee_user_id'][] = (string) trans('workflow.task_assignee_required');
        }
        if (! empty($user) && ! DB::table('users')->where('id', $user)->whereNull('deleted_at')->whereIn('status', ['active', 'invited'])->exists()) {
            $errors['assignee_user_id'][] = (string) trans('workflow.task_invalid_assignee');
        }
        if (empty($user) && ! empty($role) && ! DB::table('roles')->where('id', $role)->whereNull('deleted_at')->exists()) {
            $errors['assignee_role_id'][] = (string) trans('workflow.task_invalid_assignee');
        }
        if (($input['related_type'] ?? null) === 'employment' && ! empty($input['related_id']) && ! DB::table('employments')->where('id', $input['related_id'])->exists()) {
            $errors['related_id'][] = (string) trans('workflow.task_invalid_related');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function assigned(Task $task): void
    {
        $this->outbox->record(DomainEventTypes::TASK_ASSIGNED, 'task', $task->id, [
            'task_id' => $task->id, 'assignee_user_id' => $task->assignee_user_id, 'assignee_role_id' => $task->assignee_role_id, 'due_on' => $task->due_on?->toDateString(),
        ]);
    }
}
