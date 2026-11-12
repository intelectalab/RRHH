<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\JobCatalogService;
use App\Modules\Organization\Infrastructure\Models\JobCategory;
use App\Modules\Organization\Infrastructure\Models\JobFamily;
use App\Modules\Organization\Infrastructure\Models\JobLevel;
use App\Shared\Query\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ORG-5 · Familias, niveles y categorías laborales del catálogo de puestos (catálogos configurables
 * de toda la organización). El tipo llega por la ruta (defaults kind = family | level | category).
 */
final class JobCatalogController
{
    public function __construct(private readonly JobCatalogService $catalogs) {}

    public function index(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $class = JobCatalogService::model($kind);
        /** @var Builder<Model> $query */
        $query = $class::query();
        $status = TableQuery::filter($request, 'status');
        if ($status === 'active' || $status === 'archived') {
            $query->where('status', $status === 'active' ? 'active' : 'inactive');
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }
        $sorts = ['name' => 'name', 'code' => 'code', 'status' => 'status', 'created_at' => 'created_at'];
        if ($kind !== 'family') {
            $sorts['rank'] = 'rank';
        }
        $page = (new TableQuery($sorts, ['status']))->page($query, $request, $kind === 'family' ? 'name' : 'rank');
        /** @var array<string, Model> $models */
        $models = $class::query()->whereIn('id', $page['ids'])->get()->keyBy('id')->all();
        $data = [];
        foreach ($page['ids'] as $id) {
            $item = $models[$id] ?? null;
            if ($item instanceof JobFamily || $item instanceof JobLevel || $item instanceof JobCategory) {
                $data[] = $this->catalogs->present($kind, $item);
            }
        }

        return response()->json(['data' => $data, 'meta' => $page['meta']]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $kind = $this->kind($request);

        return $this->respond($kind, $this->find($kind, $id));
    }

    public function store(Request $request): JsonResponse
    {
        $kind = $this->kind($request);
        $input = $request->validate($this->rules($kind, true));

        return $this->respond($kind, $this->catalogs->create($kind, $input), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $kind = $this->kind($request);
        $item = $this->find($kind, $id);
        $input = $request->validate($this->rules($kind, false));
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($kind, $this->catalogs->update($kind, $item, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'archive');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'restore');
    }

    private function transition(Request $request, string $id, string $action): JsonResponse
    {
        $kind = $this->kind($request);
        $item = $this->find($kind, $id);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($kind, $this->catalogs->transition($kind, $item, $action, isset($data['reason']) ? (string) $data['reason'] : null));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $kind, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $rules = [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
        if ($kind === 'level') {
            $rules['rank'] = [$req, 'integer', 'min:1', 'max:999'];
        }
        if ($kind === 'category') {
            $rules['rank'] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'];
            $rules['functions'] = ['sometimes', 'nullable', 'string', 'max:5000'];
            $rules['responsibility_level'] = ['sometimes', 'nullable', 'string', 'max:500'];
            $rules['requirements'] = ['sometimes', 'nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    private function kind(Request $request): string
    {
        $kind = $request->route('kind');

        return is_string($kind) && in_array($kind, array_keys(JobCatalogService::KINDS), true) ? $kind : abort(404);
    }

    private function find(string $kind, string $id): JobFamily|JobLevel|JobCategory
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $item = JobCatalogService::model($kind)::query()->whereKey($id)->first();
        abort_unless($item instanceof JobFamily || $item instanceof JobLevel || $item instanceof JobCategory, 404);

        return $item;
    }

    private function respond(string $kind, JobFamily|JobLevel|JobCategory $item, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->catalogs->present($kind, $item)], $status)
            ->header('ETag', '"'.$item->lock_version.'"');
    }
}
