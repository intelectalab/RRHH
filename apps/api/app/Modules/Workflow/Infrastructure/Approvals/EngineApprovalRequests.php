<?php

namespace App\Modules\Workflow\Infrastructure\Approvals;

use App\Modules\Workflow\Application\ApprovalEngine;
use App\Modules\Workflow\Infrastructure\Models\ApprovalRequest;
use App\Modules\Workflow\Infrastructure\Models\WorkflowStep;
use App\Modules\Workflow\Infrastructure\Models\WorkflowTask;
use App\Shared\Workflow\ApprovalRequests;
use Illuminate\Support\Facades\DB;

/**
 * POS-3 · Implementación del puerto ApprovalRequests con el motor de aprobaciones (WFL-2).
 */
final class EngineApprovalRequests implements ApprovalRequests
{
    /** Estados en los que la aprobación sigue abierta y se puede cancelar. */
    private const OPEN = ['pending', 'running', 'escalated'];

    public function __construct(private readonly ApprovalEngine $engine) {}

    public function start(string $subjectType, string $subjectId, string $requesterUserId, array $context = []): string
    {
        return $this->engine->start($subjectType, $subjectId, $requesterUserId, $context)->id;
    }

    public function cancel(string $approvalRequestId, ?string $reason): void
    {
        $request = ApprovalRequest::query()->find($approvalRequestId);
        if ($request instanceof ApprovalRequest && in_array((string) $request->status, self::OPEN, true)) {
            $this->engine->cancel($request, $reason);
        }
    }

    public function summary(string $approvalRequestId): ?array
    {
        $request = ApprovalRequest::query()->find($approvalRequestId);
        if (! $request instanceof ApprovalRequest) {
            return null;
        }
        $level = $request->current_step_id === null ? null : (int) WorkflowStep::query()->whereKey($request->current_step_id)->value('sort_order');
        $names = [];
        if (in_array((string) $request->status, self::OPEN, true)) {
            $tasks = WorkflowTask::query()->where('workflow_instance_id', $request->id)->where('status', 'pending')->get();
            foreach ($tasks as $task) {
                if ($task->assignee_user_id !== null) {
                    $names[] = (string) DB::table('users')->where('id', $task->assignee_user_id)->value('display_name');
                } elseif ($task->assignee_role_id !== null) {
                    $names[] = (string) DB::table('roles')->where('id', $task->assignee_role_id)->value('name');
                }
            }
        }

        return [
            'id' => $request->id,
            'status' => (string) $request->status,
            'level' => $level,
            'pending_approvers' => array_values(array_unique(array_filter($names, fn (string $n) => $n !== ''))),
        ];
    }
}
