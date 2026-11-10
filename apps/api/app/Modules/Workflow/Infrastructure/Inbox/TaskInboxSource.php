<?php

namespace App\Modules\Workflow\Infrastructure\Inbox;

use App\Modules\Workflow\Application\TaskService;
use App\Modules\Workflow\Infrastructure\Models\Task;
use App\Shared\Inbox\InboxSource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tareas de RRHH abiertas asignadas al usuario o a uno de sus roles.
 */
final class TaskInboxSource implements InboxSource
{
    public function __construct(private readonly TaskService $tasks) {}

    public function kind(): string
    {
        return 'task';
    }

    public function permission(): string
    {
        return 'workflow.tasks.view';
    }

    public function items(string $userId, int $limit): array
    {
        return $this->query($userId)->orderByRaw('due_on asc nulls last')->orderBy('created_at')->limit($limit)->get()
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'type' => $t->priority,
                'title' => $t->title,
                'subtitle' => null,
                'due_at' => $t->due_on?->toDateString(),
                'overdue' => $t->due_on !== null && $t->due_on->lt(today()),
                'created_at' => $t->created_at?->toIso8601String(),
                'href' => '/tareas?id='.$t->id,
            ])->values()->all();
    }

    public function count(string $userId): int
    {
        return $this->query($userId)->count();
    }

    /**
     * @return Builder<Task>
     */
    private function query(string $userId): Builder
    {
        $query = Task::query()->whereIn('status', ['pending', 'in_progress']);
        $this->tasks->applyScope($query, $userId, null, true);

        return $query;
    }
}
