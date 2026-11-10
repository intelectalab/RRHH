<?php

namespace App\Modules\Workflow\Http\Controllers;

use App\Modules\Workflow\Application\ApprovalFlowService;
use App\Modules\Workflow\Infrastructure\Models\ApprovalFlow;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WFL-1 · Configuración de flujos de aprobación.
 */
final class ApprovalFlowController
{
    public function __construct(private readonly ApprovalFlowService $flows) {}

    public function index(Request $request): JsonResponse
    {
        $params = PaginationParams::fromRequest($request);
        $query = ApprovalFlow::query();
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $query->where('name', 'ilike', '%'.addcslashes($q, '%_\\').'%');
        }
        $type = $request->query('subject_type', $request->input('filter.subject_type'));
        if (is_string($type) && $type !== '') {
            $query->where('subject_type', $type);
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'id', 'desc');
        $ids = array_column($page['data'], 'id');
        $models = ApprovalFlow::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => collect($ids)->map(fn ($id) => $models->get($id))->filter()->values()->map(fn (ApprovalFlow $f) => $this->flows->present($f))->all(),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        return $this->respond($this->flows->create($data), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $flow = $this->find($id);
        $data = $request->validate($this->rules(false));

        return $this->respond($this->flows->update($flow, $data));
    }

    public function publish(string $id): JsonResponse
    {
        $flow = $this->find($id);

        return $this->respond($this->flows->publish($flow, $this->flows->actorId()));
    }

    public function retire(string $id): JsonResponse
    {
        return $this->respond($this->flows->retire($this->find($id)));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:160'],
            'subject_type' => [$req, 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.]*$/'],
            'steps' => ['sometimes', 'array'],
            'steps.levels' => ['sometimes', 'array', 'max:10'],
            'conditions' => ['sometimes', 'array'],
        ];
    }

    private function find(string $id): ApprovalFlow
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var ApprovalFlow */
        return ApprovalFlow::query()->whereKey($id)->firstOrFail();
    }

    private function respond(ApprovalFlow $flow, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->flows->present($flow->refresh())], $status);
    }
}
