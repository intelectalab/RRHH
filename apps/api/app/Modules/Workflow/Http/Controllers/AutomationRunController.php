<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\AutomationRunner;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Modules\Workflow\Infrastructure\Models\AutomationRun;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WFL-5 · Centro de automatizaciones: qué corrió, con qué resultado, y reintento o cancelación de fallidas.
 */
final class AutomationRunController
{
    public function __construct(private readonly AutomationRunner $runner) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = AutomationRun::query();
        $status = $request->input('filter.status', $request->query('status'));
        if (in_array($status, ['running', 'succeeded', 'failed', 'skipped', 'queued', 'waiting', 'cancelled'], true)) {
            $query->where('status', $status);
        }
        $rule = $request->input('filter.workflow_id', $request->query('workflow_id'));
        if (is_string($rule) && preg_match('/^[0-9a-f-]{36}$/i', $rule) === 1) {
            $query->where('automation_rule_id', $rule);
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $models = AutomationRun::query()->whereIn('id', $ids)->get()->keyBy('id');
        $names = AutomationRule::withTrashed()->whereIn('id', $models->pluck('automation_rule_id')->unique()->all())->pluck('name', 'id');

        return response()->json([
            'data' => collect($ids)->map(fn ($id) => $models->get($id))->filter()->values()->map(fn (AutomationRun $r) => $this->present($r, $names->get($r->automation_rule_id)))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->find($id));
    }

    public function retry(string $id): JsonResponse
    {
        return $this->respond($this->runner->retry($this->find($id)));
    }

    public function cancel(string $id): JsonResponse
    {
        return $this->respond($this->runner->cancel($this->find($id)));
    }

    private function find(string $id): AutomationRun
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var AutomationRun */
        return AutomationRun::query()->whereKey($id)->firstOrFail();
    }

    private function respond(AutomationRun $run): JsonResponse
    {
        $name = AutomationRule::withTrashed()->whereKey($run->automation_rule_id)->value('name');

        return response()->json(['data' => $this->present($run->refresh(), is_string($name) ? $name : null)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AutomationRun $run, mixed $ruleName): array
    {
        $log = $run->log ?? [];

        return [
            'id' => $run->id,
            'workflow_id' => $run->automation_rule_id,
            'workflow_name' => is_string($ruleName) ? $ruleName : null,
            'trigger_event_id' => $run->trigger_event_id,
            'event_type' => $log['event_type'] ?? null,
            'status' => $run->status,
            'attempts' => (int) ($log['attempts'] ?? 1),
            'next_retry_at' => $log['next_retry_at'] ?? null,
            'actions' => array_values((array) ($log['actions'] ?? [])),
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => $run->error,
            'allowed_actions' => match ($run->status) {
                'failed' => ['retry', 'cancel'],
                'waiting', 'queued' => ['cancel'],
                default => [],
            },
        ];
    }
}
