<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\VisualFlowService;
use App\Modules\Workflow\Infrastructure\Models\VisualFlow;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * WFL-6 · Constructor visual de flujos (Configuración → Constructor de flujos).
 */
final class VisualFlowController
{
    public function __construct(private readonly VisualFlowService $flows) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = VisualFlow::query();
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $models = VisualFlow::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => collect($ids)->map(fn ($id) => $models->get($id))->filter()->values()->map(fn (VisualFlow $f) => $this->flows->present($f))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->respond($this->flows->create($this->input($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->flows->update($this->find($id), $this->input($request)));
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $user = $request->user()?->getAuthIdentifier();

        return $this->respond($this->flows->publish($this->find($id), is_string($user) ? $user : null));
    }

    public function activate(string $id): JsonResponse
    {
        return $this->respond($this->flows->transition($this->find($id), 'activate'));
    }

    public function pause(string $id): JsonResponse
    {
        return $this->respond($this->flows->transition($this->find($id), 'pause'));
    }

    public function retire(string $id): JsonResponse
    {
        return $this->respond($this->flows->transition($this->find($id), 'retire'));
    }

    /**
     * Modo de prueba: el recorrido de un caso de ejemplo, sin crear tareas, avisos ni aprobaciones.
     */
    public function test(Request $request, string $id): JsonResponse
    {
        $flow = $this->find($id);
        $payload = $request->input('payload', []);
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw ValidationException::withMessages(['payload' => [(string) trans('workflow.automation.invalid_payload')]]);
        }

        return response()->json(['data' => $this->flows->test($flow, $payload, $request->input('graph'))]);
    }

    /**
     * «status» no se acepta aquí: los estados cambian por acciones (regla 3).
     *
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return array_intersect_key($request->all(), array_flip(['name', 'description', 'graph']));
    }

    private function find(string $id): VisualFlow
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var VisualFlow */
        return VisualFlow::query()->whereKey($id)->firstOrFail();
    }

    private function respond(VisualFlow $flow, int $status = 200): JsonResponse
    {
        $fresh = VisualFlow::withTrashed()->find($flow->id) ?? $flow;

        return response()->json(['data' => $this->flows->present($fresh)], $status);
    }
}
