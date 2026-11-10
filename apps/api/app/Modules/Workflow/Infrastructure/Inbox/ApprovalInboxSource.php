<?php

namespace App\Modules\Workflow\Infrastructure\Inbox;

use App\Modules\Workflow\Application\TaskService;
use App\Shared\Inbox\InboxSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aprobaciones pendientes a nombre del usuario o de uno de sus roles (nivel en curso).
 */
final class ApprovalInboxSource implements InboxSource
{
    public function __construct(private readonly TaskService $tasks) {}

    public function kind(): string
    {
        return 'approval';
    }

    public function permission(): string
    {
        return 'workflow.approval_requests.view';
    }

    public function items(string $userId, int $limit): array
    {
        $rows = $this->query($userId)
            ->leftJoin('users as req', 'req.id', '=', 'i.started_by_user_id')
            ->leftJoin('workflow_steps as s', 's.id', '=', 't.workflow_step_id')
            ->orderByRaw('t.due_at asc nulls last')->orderBy('t.created_at')
            ->limit($limit)
            ->get(['i.id', 'i.subject_type', 'i.status', 'req.display_name as requester', 's.name as step', 's.sort_order', 't.due_at', 't.created_at']);

        return $rows->map(fn ($r) => [
            'id' => (string) $r->id,
            'type' => (string) $r->subject_type,
            'title' => (string) ($r->requester ?? ''),
            'subtitle' => $r->step === null ? null : (string) $r->step,
            'due_at' => $r->due_at === null ? null : Carbon::parse($r->due_at)->toIso8601String(),
            'overdue' => $r->due_at !== null && Carbon::parse($r->due_at)->isPast(),
            'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            'href' => '/aprobaciones?id='.$r->id,
            'data' => ['level' => (int) $r->sort_order, 'escalated' => $r->status === 'escalated'],
        ])->values()->all();
    }

    public function count(string $userId): int
    {
        return $this->query($userId)->count();
    }

    private function query(string $userId): Builder
    {
        $roles = $this->tasks->roleIdsOf($userId);

        return DB::table('workflow_tasks as t')
            ->join('workflow_instances as i', fn ($j) => $j->on('i.id', '=', 't.workflow_instance_id')->on('i.current_step_id', '=', 't.workflow_step_id'))
            ->where('t.status', 'pending')
            ->whereIn('i.status', ['running', 'escalated', 'pending'])
            ->where(fn ($q) => $q->whereNull('i.started_by_user_id')->orWhere('i.started_by_user_id', '!=', $userId))
            ->where(fn ($w) => $w->where('t.assignee_user_id', $userId)
                ->orWhere(fn ($r) => $r->whereNull('t.assignee_user_id')->whereIn('t.assignee_role_id', $roles === [] ? [''] : $roles)));
    }
}
