<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\CostCenterService;
use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Pagination\CursorPaginator;
use App\Shared\Pagination\PaginationParams;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ORG-4 · Centros de costo por empresa con código, jerarquía y vigencia. Alcance por empresa en la consulta.
 */
final class CostCenterController
{
    private const FILTERS = ['status', 'legal_entity_id', 'parent_id'];

    public function __construct(
        private readonly CostCenterService $centers,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:'.implode(',', self::FILTERS)],
            'filter.status' => ['sometimes', Rule::in(['active', 'closed', 'archived'])],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.parent_id' => ['sometimes', 'uuid'],
        ]);
        if ($filters->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $params = PaginationParams::fromRequest($request);
        $query = CostCenter::query();
        $this->scope->applyLegalEntity($query, $request, 'org.cost_centers.view');

        $status = $request->input('filter.status');
        if (is_string($status)) {
            $query->where('status', $status === 'archived' ? 'inactive' : $status);
        }
        $parent = $request->input('filter.parent_id');
        if (is_string($parent)) {
            $query->where('parent_id', $parent);
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('accounting_code', 'ilike', $like));
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'code');
        $ids = array_column($page['data'], 'id');
        $models = CostCenter::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => array_values(array_map(
                fn (CostCenter $c) => $this->centers->present($c),
                array_filter(array_map(fn ($id) => $models->get($id), $ids), fn ($c) => $c instanceof CostCenter),
            )),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'org.cost_centers.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->normalize($request->validate($this->rules(true)));
        $this->guardEntityInScope($request, $input['legal_entity_id'] ?? null, 'org.cost_centers.create');

        return $this->respond($this->centers->create($input), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $center = $this->find($request, $id, 'org.cost_centers.update');
        $input = $this->normalize($request->validate($this->rules(false)));
        if (array_key_exists('legal_entity_id', $input)) {
            $this->guardEntityInScope($request, $input['legal_entity_id'], 'org.cost_centers.update');
        }
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($this->centers->update($center, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $center = $this->find($request, $id, 'org.cost_centers.update');
        $data = $request->validate([
            'closed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return $this->respond($this->centers->transition($center, 'close', $this->reason($data), isset($data['closed_on']) ? (string) $data['closed_on'] : null));
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'reopen', 'org.cost_centers.update');
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'archive', 'org.cost_centers.archive');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'restore', 'org.cost_centers.restore');
    }

    private function transition(Request $request, string $id, string $action, string $permission): JsonResponse
    {
        $center = $this->find($request, $id, $permission);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->centers->transition($center, $action, $this->reason($data)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reason(array $data): ?string
    {
        return isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'parent_id' => ['sometimes', 'nullable', 'uuid'],
            'accounting_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'opened_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper(trim($input['code']));
        }
        if (array_key_exists('accounting_code', $input) && is_string($input['accounting_code']) && trim($input['accounting_code']) === '') {
            $input['accounting_code'] = null;
        }

        return $input;
    }

    /**
     * @return array<int, string>|null
     */
    private function allowedEntities(Request $request, string $permission): ?array
    {
        $user = $request->user();

        return $user === null ? [] : $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
    }

    private function guardEntityInScope(Request $request, mixed $legalEntityId, string $permission): void
    {
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null && (! is_string($legalEntityId) || ! in_array($legalEntityId, $allowed, true))) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.out_of_scope')]]);
        }
    }

    private function find(Request $request, string $id, string $permission): CostCenter
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = CostCenter::query()->whereKey($id);
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null) {
            $query->whereIn('legal_entity_id', $allowed);
        }
        $center = $query->first();
        abort_unless($center instanceof CostCenter, 404);

        return $center;
    }

    private function respond(CostCenter $center, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->centers->present($center)], $status)
            ->header('ETag', '"'.$center->lock_version.'"');
    }
}
