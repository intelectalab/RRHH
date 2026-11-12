<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\JobProfileService;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\JobProfileVersion;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Query\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ORG-5 · Catálogo de puestos (job_profiles), con la historia de versiones de su descripción.
 */
final class JobProfileController
{
    private const FILTERS = ['status', 'job_family_id', 'job_level_id', 'job_category_id', 'category_pending', 'risk_job'];

    public function __construct(private readonly JobProfileService $profiles) {}

    public function index(Request $request): JsonResponse
    {
        $query = JobProfile::query();
        $status = TableQuery::filter($request, 'status');
        if ($status === 'active' || $status === 'archived') {
            $status === 'active' ? $query->where('status', '<>', 'inactive') : $query->where('status', 'inactive');
        }
        foreach (['job_family_id', 'job_level_id', 'job_category_id'] as $f) {
            $value = TableQuery::filter($request, $f);
            if ($value !== null) {
                if (preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
                    throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
                }
                $query->where($f, $value);
            }
        }
        // RN-ORG-07: puestos sin categoría laboral, pendientes en el cuadro de categorías y funciones.
        $pending = TableQuery::filter($request, 'category_pending');
        if ($pending !== null) {
            in_array($pending, ['true', '1'], true) ? $query->whereNull('job_category_id') : $query->whereNotNull('job_category_id');
        }
        $risk = TableQuery::filter($request, 'risk_job');
        if ($risk !== null) {
            $query->where('is_risk_job', in_array($risk, ['true', '1'], true));
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }

        $page = (new TableQuery(
            ['name' => 'name', 'code' => 'code', 'status' => 'status', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
            self::FILTERS,
        ))->page($query, $request, 'name');
        $models = JobProfile::query()->whereIn('id', $page['ids'])->get()->keyBy('id');
        $data = [];
        foreach ($page['ids'] as $id) {
            $profile = $models->get($id);
            if ($profile instanceof JobProfile) {
                $data[] = $this->profiles->present($profile);
            }
        }

        return response()->json(['data' => $data, 'meta' => $page['meta']]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond($this->find($id));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));

        return $this->respond($this->profiles->create($input, $this->userId($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $profile = $this->find($id);
        $input = $request->validate($this->rules(false));
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($this->profiles->update($profile, $input, $ifMatch === '' ? null : (int) $ifMatch, $this->userId($request)));
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'archive');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'restore');
    }

    /** Versiones de la descripción, de la más reciente a la más antigua. */
    public function versions(string $id): JsonResponse
    {
        $profile = $this->find($id);
        $versions = JobProfileVersion::query()->where('job_profile_id', $profile->id)->orderByDesc('version')->get();

        return response()->json([
            'data' => $versions->map(fn (JobProfileVersion $v) => $this->profiles->presentVersion($v))->values()->all(),
        ]);
    }

    private function transition(Request $request, string $id, string $action): JsonResponse
    {
        $profile = $this->find($id);
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->respond($this->profiles->transition($profile, $action, isset($data['reason']) ? (string) $data['reason'] : null));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'name' => [$req, 'string', 'min:2', 'max:150'],
            'job_family_id' => ['sometimes', 'nullable', 'uuid'],
            'job_level_id' => ['sometimes', 'nullable', 'uuid'],
            'job_category_id' => ['sometimes', 'nullable', 'uuid'],
            'mission' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'functions' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'occupation_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'risk_job' => ['sometimes', 'boolean'],
            'trust_classification' => ['sometimes', Rule::in(['none', 'trust', 'management'])],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    private function find(string $id): JobProfile
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $profile = JobProfile::query()->whereKey($id)->first();
        abort_unless($profile instanceof JobProfile, 404);

        return $profile;
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }

    private function respond(JobProfile $profile, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->profiles->present($profile)], $status)
            ->header('ETag', '"'.$profile->lock_version.'"');
    }
}
