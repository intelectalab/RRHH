<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Modules\Workflow\Infrastructure\Models\WorkflowTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Vista de una solicitud de aprobación para el centro de aprobaciones (WFL-4) y la bandeja (WFL-9).
 */
final class ApprovalRequestPresenter
{
    public function __construct(private readonly ApproverResolver $resolver) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ApprovalRequest $request, ?string $viewerId): array
    {
        $version = DB::table('workflow_definition_versions as v')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('v.id', $request->workflow_definition_version_id)->first(['d.id', 'd.name']);
        $steps = DB::table('workflow_steps')->where('workflow_definition_version_id', $request->workflow_definition_version_id)->where('kind', 'approval')->orderBy('sort_order')->get(['id', 'name', 'sort_order']);
        $current = $steps->firstWhere('id', $request->current_step_id);

        $tasks = WorkflowTask::query()->where('workflow_instance_id', $request->id)->orderBy('created_at')->orderBy('sequence')->get();
        $names = DB::table('users')->whereIn('id', $tasks->pluck('assignee_user_id')->merge($tasks->pluck('delegated_from_user_id'))->push($request->started_by_user_id)->filter()->unique()->values())->pluck('display_name', 'id');
        $roles = DB::table('roles')->whereIn('id', $tasks->pluck('assignee_role_id')->filter()->unique()->values())->pluck('name', 'id');
        $decisions = DB::table('workflow_decisions')->whereIn('workflow_task_id', $tasks->pluck('id'))->orderBy('decided_at')->get()->groupBy('workflow_task_id');
        $stepNames = $steps->pluck('name', 'id');
        $stepOrder = $steps->pluck('sort_order', 'id');

        $open = ! in_array($request->status, ['approved', 'rejected', 'cancelled'], true);
        $canDecide = false;
        $dueAt = null;
        $rows = [];
        foreach ($tasks as $task) {
            $mine = $viewerId !== null && ($task->assignee_user_id === $viewerId
                || ($task->assignee_user_id === null && $task->assignee_role_id !== null && $this->resolver->userHasRole($viewerId, (string) $task->assignee_role_id)));
            if ($open && $task->status === 'pending' && $task->workflow_step_id === $request->current_step_id) {
                $canDecide = $canDecide || ($mine && $viewerId !== $request->started_by_user_id);
                $dueAt = $dueAt === null || ($task->due_at !== null && $task->due_at->lt($dueAt)) ? $task->due_at : $dueAt;
            }
            $decision = collect($decisions->get($task->id, []))->whereIn('decision', ['approve', 'reject'])->last();
            $rows[] = [
                'id' => $task->id,
                'level' => (int) ($stepOrder[$task->workflow_step_id] ?? 0),
                'level_name' => (string) ($stepNames[$task->workflow_step_id] ?? ''),
                'assignee_user_id' => $task->assignee_user_id,
                'assignee_name' => $task->assignee_user_id !== null ? ($names[$task->assignee_user_id] ?? null) : ($roles[$task->assignee_role_id] ?? null),
                'assignee_kind' => $task->assignee_user_id !== null ? 'user' : 'role',
                'delegated_from_name' => $task->delegated_from_user_id !== null ? ($names[$task->delegated_from_user_id] ?? null) : null,
                'status' => $task->status,
                'due_at' => $task->due_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'comment' => $decision?->comment,
                'mine' => $mine,
            ];
        }

        return [
            'id' => $request->id,
            'subject_type' => (string) $request->subject_type,
            'subject_id' => (string) $request->subject_id,
            'flow_id' => (string) ($version->id ?? ''),
            'flow_name' => (string) ($version->name ?? ''),
            'current_step' => (int) ($current->sort_order ?? 0),
            'current_step_name' => $current?->name,
            'levels' => $steps->count(),
            // El contrato expone «pendiente» mientras la solicitud sigue abierta; el escalamiento va aparte.
            'status' => $open ? 'pending' : (string) $request->status,
            'escalated' => $request->status === 'escalated',
            'due_at' => $dueAt?->toIso8601String(),
            'started_by_user_id' => $request->started_by_user_id,
            'requester_name' => $request->started_by_user_id !== null ? ($names[$request->started_by_user_id] ?? null) : null,
            'tasks' => $rows,
            'can_decide' => $canDecide,
            'finished_at' => $request->finished_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Alcance «organización»/«empresa»: todas. Otros alcances: las que el usuario inició o tiene asignadas
     * (a su nombre o a uno de sus roles).
     *
     * @param  Builder<ApprovalRequest>  $query
     */
    public function applyScope(Builder $query, string $userId, ?string $scope, bool $onlyAssigned): void
    {
        $wide = in_array($scope, ['organization', 'legal_entity'], true);
        if ($wide && ! $onlyAssigned) {
            return;
        }
        $today = now()->toDateString();
        $assigned = fn ($q) => $q->select('workflow_instance_id')->from('workflow_tasks')
            ->where(fn ($w) => $w->where('assignee_user_id', $userId)->orWhere(fn ($r) => $r->whereNull('assignee_user_id')->whereIn('assignee_role_id', fn ($ur) => $ur->select('role_id')->from('user_roles')->where('user_id', $userId)
                ->where(fn ($v) => $v->whereNull('valid_from')->orWhere('valid_from', '<=', $today))
                ->where(fn ($v) => $v->whereNull('valid_to')->orWhere('valid_to', '>=', $today)))));

        if ($onlyAssigned) {
            $query->whereIn('id', fn ($q) => $assigned($q)->where('status', 'pending'))->where('started_by_user_id', '!=', $userId);

            return;
        }
        $query->where(fn ($w) => $w->where('started_by_user_id', $userId)->orWhereIn('id', $assigned));
    }
}
