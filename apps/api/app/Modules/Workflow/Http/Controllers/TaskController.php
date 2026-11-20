<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\TaskService;
use App\Modules\Workflow\Infrastructure\Models\Task;
use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WFL-8 · Tareas de RRHH.
 */
final class TaskController
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly DataScope $dataScope,
        private readonly CustomFieldValues $customFields,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $userId = $this->userId($request);
        $query = Task::query();
        $mine = in_array((string) $request->input('filter.assigned', $request->query('assigned', '')), ['me', '1', 'true'], true);
        $this->scoped($query, $request, 'workflow.tasks.view', $mine);

        $status = $request->input('filter.status', $request->query('status'));
        if ($status === 'open') {
            $query->whereIn('status', ['pending', 'in_progress']);
        } elseif (in_array($status, ['pending', 'in_progress', 'done', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        if (in_array($request->input('filter.overdue', $request->query('overdue')), ['1', 'true'], true)) {
            $query->whereIn('status', ['pending', 'in_progress'])->where('due_on', '<', today()->toDateString());
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('title', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $models = Task::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => collect($ids)->map(fn ($id) => $models->get($id))->filter()->values()->map(fn (Task $t) => $this->tasks->present($t, $userId))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id, 'workflow.tasks.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $custom = $this->customFields->validate('task', $this->customInput($request), true, $this->permissions($request));
        $task = DB::transaction(function () use ($data, $request, $custom): Task {
            $task = $this->tasks->create($data, $this->userId($request));
            $this->customFields->save('task', $task->id, $custom);

            return $task;
        });

        return $this->respond($request, $task, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id, 'workflow.tasks.update');
        $data = $request->validate($this->rules(false));
        $custom = $this->customFields->validate('task', $this->customInput($request), false, $this->permissions($request));
        $task = DB::transaction(function () use ($task, $data, $custom): Task {
            $task = $this->tasks->update($task, $data);
            $this->customFields->save('task', $task->id, $custom);

            return $task;
        });

        return $this->respond($request, $task);
    }

    /** UI-12 · Empezar una tarea pendiente (el tablero la pasa a «En curso»). */
    public function start(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id, 'workflow.tasks.complete');

        return $this->respond($request, $this->tasks->transition($task, 'start', null));
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id, 'workflow.tasks.complete');
        $data = $request->validate(['comment' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->tasks->transition($task, 'complete', $data['comment'] ?? null));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $task = $this->find($request, $id, 'workflow.tasks.cancel');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:2000']]);

        return $this->respond($request, $this->tasks->transition($task, 'cancel', $data['reason'] ?? null));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assignee_user_id' => ['sometimes', 'nullable', 'uuid'],
            'assignee_role_id' => ['sometimes', 'nullable', 'uuid'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'related_type' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'related_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @param  Builder<Task>  $query
     */
    private function scoped(Builder $query, Request $request, string $permission, bool $mine): void
    {
        $userId = $this->userId($request);
        $effective = $request->attributes->get('effective_permissions');
        $scope = is_object($effective) && method_exists($effective, 'scopeFor') ? $effective->scopeFor($permission) : null;
        $team = $scope === 'team' ? $this->dataScope->employmentScope($userId, $permission) : null;
        $this->tasks->applyScope($query, $userId, is_string($scope) ? $scope : null, $mine, $team);
    }

    /**
     * Fuera de alcance = no encontrado (RN-SEG-04).
     */
    private function find(Request $request, string $id, string $permission): Task
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = Task::query()->whereKey($id);
        $this->scoped($query, $request, $permission, false);

        /** @var Task */
        return $query->firstOrFail();
    }

    private function userId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }

    /**
     * @return array<string, mixed>
     */
    private function customInput(Request $request): array
    {
        $value = $request->input('custom_fields', []);

        return is_array($value) ? $value : [];
    }

    private function permissions(Request $request): ?EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : null;
    }

    private function respond(Request $request, Task $task, int $status = 200): JsonResponse
    {
        $data = $this->tasks->present($task->refresh(), $this->userId($request)) + ['custom_fields' => (object) $this->customFields->read('task', $task->id, $this->permissions($request))];

        return response()->json(['data' => $data], $status);
    }
}
