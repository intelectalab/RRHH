<?php

namespace App\Modules\Workflow\Infrastructure\Calendar;

use App\Modules\Workflow\Application\TaskService;
use App\Modules\Workflow\Infrastructure\Models\Task;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * CFG-8 · Tareas abiertas con fecha límite en el calendario central, con el mismo alcance que la
 * bandeja de tareas (propias, de sus roles, creadas por el usuario o de su equipo).
 */
final class TaskCalendarSource implements CalendarSource
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly DataScope $dataScope,
    ) {}

    public function key(): string
    {
        return 'tasks';
    }

    public function permission(): string
    {
        return 'workflow.tasks.view';
    }

    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
    {
        $userId = (string) $request->user()?->getAuthIdentifier();
        $scope = $permissions?->scopeFor('workflow.tasks.view');
        $team = $scope === 'team' ? $this->dataScope->employmentScope($userId, 'workflow.tasks.view') : null;

        $query = Task::query()->whereIn('status', ['pending', 'in_progress'])
            ->whereBetween('due_on', [$from->toDateString(), $to->toDateString()]);
        $this->tasks->applyScope($query, $userId, $scope, false, $team);

        return $query->orderBy('due_on')->orderBy('title')->limit(500)->get()->map(fn (Task $t): array => [
            'id' => $t->id,
            'title' => $t->title,
            'subtitle' => null,
            'starts_on' => (string) $t->due_on?->toDateString(),
            'ends_on' => (string) $t->due_on?->toDateString(),
            'link' => '/tareas?id='.$t->id,
            'tone' => $t->due_on !== null && $t->due_on->isPast() && ! $t->due_on->isToday() ? 'overdue' : $t->priority,
        ])->all();
    }
}
