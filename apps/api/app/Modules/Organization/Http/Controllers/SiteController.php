<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\SiteService;
use App\Modules\Organization\Infrastructure\Models\Site;
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
 * ORG-2 · Sedes, sucursales y ubicaciones. El listado respeta el alcance por empresa del usuario
 * (las sedes compartidas, sin empresa, solo las ve quien tiene alcance de organización).
 */
final class SiteController
{
    private const FILTERS = ['status', 'legal_entity_id', 'kind', 'risk_site'];

    /** Radio máximo de la geocerca (dato técnico de la marcación, no legal). */
    private const MAX_RADIUS_M = 10000;

    public function __construct(
        private readonly SiteService $sites,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:'.implode(',', self::FILTERS)],
            'filter.status' => ['sometimes', Rule::in(['active', 'archived'])],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.kind' => ['sometimes', Rule::in(Site::KINDS)],
            'filter.risk_site' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
        ]);
        if ($filters->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $params = PaginationParams::fromRequest($request);
        $query = Site::query();
        $this->scope->applyLegalEntity($query, $request, 'org.sites.view');

        $status = $request->input('filter.status');
        if (is_string($status)) {
            $query->where('status', $status === 'active' ? 'active' : 'inactive');
        }
        foreach (['legal_entity_id', 'kind'] as $f) {
            $value = $request->input('filter.'.$f);
            if (is_string($value)) {
                $query->where($f, $value);
            }
        }
        $risk = $request->input('filter.risk_site');
        if (is_string($risk)) {
            $query->where('is_risk_site', in_array($risk, ['true', '1'], true));
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('address', 'ilike', $like));
        }

        $page = CursorPaginator::paginate($query, $params->perPage, $params->cursor, 'name');
        $ids = array_column($page['data'], 'id');
        $models = Site::query()->whereIn('id', $ids)->get()->keyBy('id');

        return response()->json([
            'data' => array_values(array_map(
                fn (Site $s) => $this->sites->present($s),
                array_filter(array_map(fn ($id) => $models->get($id), $ids), fn ($s) => $s instanceof Site),
            )),
            'meta' => ['per_page' => $page['meta']['per_page'], 'next_cursor' => $page['meta']['next_cursor']],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($this->find($request, $id, 'org.sites.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));
        $this->guardEntityInScope($request, $input['legal_entity_id'] ?? null, 'org.sites.create');

        return $this->respond($this->sites->create($this->normalize($input)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $site = $this->find($request, $id, 'org.sites.update');
        $input = $request->validate($this->rules(false));
        if (array_key_exists('legal_entity_id', $input)) {
            $this->guardEntityInScope($request, $input['legal_entity_id'], 'org.sites.update');
        }
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($this->sites->update($site, $this->normalize($input), $ifMatch === '' ? null : (int) $ifMatch));
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
        $site = $this->find($request, $id, 'org.sites.'.$action);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->sites->transition($site, $action, isset($data['reason']) ? (string) $data['reason'] : null));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'kind' => ['sometimes', Rule::in(Site::KINDS)],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'district_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['sometimes', 'nullable', 'integer', 'min:10', 'max:'.self::MAX_RADIUS_M],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'holiday_calendar_id' => ['sometimes', 'nullable', 'uuid'],
            'establishment_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'risk_site' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach (['latitude', 'longitude'] as $k) {
            if (array_key_exists($k, $input) && $input[$k] !== null) {
                $input[$k] = number_format((float) $input[$k], 6, '.', '');
            }
        }
        if (array_key_exists('code', $input) && is_string($input['code'])) {
            $input['code'] = strtoupper(trim($input['code']));
        }
        if (array_key_exists('country', $input) && is_string($input['country'])) {
            $input['country'] = strtoupper($input['country']);
        }
        foreach (['address', 'district_code', 'establishment_code', 'timezone'] as $k) {
            if (array_key_exists($k, $input) && is_string($input[$k]) && trim($input[$k]) === '') {
                $input[$k] = null;
            }
        }

        return $input;
    }

    private function find(Request $request, string $id, string $permission): Site
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = Site::query()->whereKey($id);
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null) {
            $query->whereIn('legal_entity_id', $allowed);
        }
        $site = $query->first();
        abort_unless($site instanceof Site, 404);

        return $site;
    }

    /**
     * @return array<int, string>|null null = toda la organización
     */
    private function allowedEntities(Request $request, string $permission): ?array
    {
        $user = $request->user();

        return $user === null ? [] : $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
    }

    /** Con alcance de empresa solo se crean o mueven sedes a empresas del propio alcance (no compartidas). */
    private function guardEntityInScope(Request $request, mixed $legalEntityId, string $permission): void
    {
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null && (! is_string($legalEntityId) || ! in_array($legalEntityId, $allowed, true))) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.out_of_scope')]]);
        }
    }

    private function respond(Site $site, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->sites->present($site)], $status)
            ->header('ETag', '"'.$site->lock_version.'"');
    }
}
