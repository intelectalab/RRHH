<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\AutomationCatalog;
use App\Modules\Workflow\Application\AutomationRuleService;
use App\Modules\Workflow\Application\AutomationRunner;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * WFL-5 · Reglas de automatización (Configuración → Automatizaciones).
 */
final class AutomationRuleController
{
    public function __construct(
        private readonly AutomationRuleService $rules,
        private readonly AutomationRunner $runner,
        private readonly AutomationCatalog $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = AutomationRule::query();
        $status = $request->input('filter.status', $request->query('status'));
        if (in_array($status, ['draft', 'active', 'paused'], true)) {
            $query->where('status', $status);
        }
        $event = $request->input('filter.trigger_event', $request->query('trigger_event'));
        if (is_string($event) && $event !== '') {
            $query->where('trigger_event', $event);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $models = AutomationRule::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => collect($ids)->map(fn ($id) => $models->get($id))->filter()->values()->map(fn (AutomationRule $r) => $this->rules->present($r))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function catalog(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->describe()]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond($this->rules->create($this->input($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->rules->update($this->find($id), $this->input($request)));
    }

    public function activate(string $id): JsonResponse
    {
        return $this->respond($this->rules->transition($this->find($id), 'activate'));
    }

    public function pause(string $id): JsonResponse
    {
        return $this->respond($this->rules->transition($this->find($id), 'pause'));
    }

    public function retire(string $id): JsonResponse
    {
        return $this->respond($this->rules->transition($this->find($id), 'retire'));
    }

    /**
     * Prueba en seco con datos de ejemplo: no crea tareas, avisos ni aprobaciones.
     */
    public function test(Request $request, string $id): JsonResponse
    {
        $rule = $this->find($id);
        $payload = $request->input('payload', []);
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw ValidationException::withMessages(['payload' => [(string) trans('workflow.automation.invalid_payload')]]);
        }

        return response()->json(['data' => $this->runner->preview($rule, $payload)]);
    }

    /**
     * Solo los campos de la regla; «status» no se acepta aquí: los estados cambian por acciones (regla 3).
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_intersect_key($request->all(), array_flip(['name', 'trigger_event', 'conditions', 'actions']));
    }

    private function find(string $id): AutomationRule
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var AutomationRule */
        return AutomationRule::query()->whereKey($id)->firstOrFail();
    }

    private function respond(AutomationRule $rule, int $status = 200): JsonResponse
    {
        if (! $rule->trashed()) {
            $rule->refresh();
        }

        return response()->json(['data' => $this->rules->present($rule)], $status);
    }
}
