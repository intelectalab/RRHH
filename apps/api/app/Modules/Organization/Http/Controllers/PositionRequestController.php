<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\PositionRequestService;
use App\Modules\Organization\Infrastructure\Models\PositionRequest;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Query\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POS-3 · Solicitudes de posición (ME-04).
 *
 * - Quien solicita ve las suyas; con org.positions.approve se ven todas las del alcance de empresa.
 * - Solo el solicitante (o quien aprueba posiciones) edita, envía o cancela; aprobar y rechazar se hace
 *   en el centro de aprobaciones.
 * - El costo estimado es sensible (org.positions.budget.view); el solicitante siempre ve el que registró.
 */
final class PositionRequestController
{
    private const SCHEMA = 'PositionRequest';

    private const BUDGET_PERMISSION = 'org.positions.budget.view';

    private const APPROVER_PERMISSION = 'org.positions.approve';

    private const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'fulfilled'];

    public function __construct(
        private readonly PositionRequestService $requests,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->visible($request, 'org.position_requests.view');
        $status = TableQuery::filter($request, 'status') ?? $request->query('status');
        if (is_string($status) && $status !== '') {
            $wanted = explode(',', $status);
            if (array_diff($wanted, self::STATUSES) !== []) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['status', 'org_unit_id', 'mine']);
            }
            $query->whereIn('status', $wanted);
        }
        $unit = TableQuery::filter($request, 'org_unit_id') ?? $request->query('org_unit_id');
        if (is_string($unit) && $unit !== '') {
            if (preg_match('/^[0-9a-f-]{36}$/i', $unit) !== 1) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['status', 'org_unit_id', 'mine']);
            }
            $query->where('org_unit_id', $unit);
        }
        if (in_array($request->query('mine'), ['true', '1'], true)) {
            $query->where('requested_by_user_id', $this->userId($request));
        }
        $page = (new TableQuery(['created_at' => 'created_at', 'status' => 'status', 'needed_by' => 'needed_by', 'title' => 'title'], ['status', 'org_unit_id']))
            ->page($query, $request, '-created_at');
        $models = PositionRequest::query()->whereIn('id', $page['ids'])->get()->keyBy('id');
        $rows = [];
        foreach ($page['ids'] as $id) {
            $m = $models->get($id);
            if ($m instanceof PositionRequest) {
                $rows[] = $this->present($request, $m, false);
            }
        }

        return response()->json(['data' => $rows, 'meta' => $page['meta']]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id, 'org.position_requests.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));
        $this->guardEntityInScope($request, $input['legal_entity_id'] ?? null, $input['org_unit_id']);

        return $this->respond($request, $this->requests->create($input, (string) $this->userId($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $model = $this->ownedOrApprover($request, $this->find($request, $id, 'org.position_requests.create'));
        $input = $request->validate($this->rules(false));
        if (array_key_exists('legal_entity_id', $input) || array_key_exists('org_unit_id', $input)) {
            $this->guardEntityInScope($request, $input['legal_entity_id'] ?? $model->legal_entity_id, $input['org_unit_id'] ?? $model->org_unit_id);
        }
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($request, $this->requests->update($model, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $model = $this->ownedOrApprover($request, $this->find($request, $id, 'org.position_requests.create'));

        return $this->respond($request, $this->requests->submit($model, (string) $this->userId($request)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $model = $this->ownedOrApprover($request, $this->find($request, $id, 'org.position_requests.create'));
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $reason = $data['reason'] ?? $data['comment'] ?? null;

        return $this->respond($request, $this->requests->cancel($model, is_string($reason) ? $reason : null));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'legal_entity_id' => ['sometimes', 'uuid'],
            'org_unit_id' => [$req, 'uuid'],
            'job_profile_id' => [$req, 'uuid'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'min:2', 'max:150'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'reports_to_position_id' => ['sometimes', 'nullable', 'uuid'],
            'salary_band_id' => ['sometimes', 'nullable', 'uuid'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'justification' => [$req, 'string', 'min:5', 'max:2000'],
            'kind' => ['sometimes', Rule::in(['new', 'replacement', 'temporary'])],
            'replaces_employment_id' => ['sometimes', 'nullable', 'uuid'],
            'needed_by' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'estimated_cost' => ['sometimes', 'nullable', 'array:amount,currency'],
            'estimated_cost.amount' => ['required_with:estimated_cost', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'estimated_cost.currency' => ['required_with:estimated_cost', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ];
    }

    /**
     * Solicitudes que la persona puede ver: las propias; con org.positions.approve, todas las de su alcance.
     *
     * @return Builder<PositionRequest>
     */
    private function visible(Request $request, string $permission)
    {
        $query = PositionRequest::query();
        if (! $this->permissions($request)->has(self::APPROVER_PERMISSION)) {
            return $query->where('requested_by_user_id', $this->userId($request));
        }
        $allowed = $this->allowedEntities($request, self::APPROVER_PERMISSION);
        if ($allowed !== null) {
            $query->where(fn ($q) => $q->whereIn('legal_entity_id', $allowed)->orWhere('requested_by_user_id', $this->userId($request)));
        }

        return $query;
    }

    private function find(Request $request, string $id, string $permission): PositionRequest
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $model = $this->visible($request, $permission)->whereKey($id)->first();
        abort_unless($model instanceof PositionRequest, 404);

        return $model;
    }

    private function ownedOrApprover(Request $request, PositionRequest $model): PositionRequest
    {
        if ($model->requested_by_user_id !== $this->userId($request) && ! $this->permissions($request)->has(self::APPROVER_PERMISSION)) {
            throw new AyniException('forbidden', 403, (string) trans('organization.position_requests.only_requester'));
        }

        return $model;
    }

    /** Con alcance de empresa solo se solicitan plazas en empresas del propio alcance. */
    private function guardEntityInScope(Request $request, mixed $legalEntityId, mixed $orgUnitId): void
    {
        $allowed = $this->allowedEntities($request, 'org.position_requests.create');
        if ($allowed === null) {
            return;
        }
        $entity = is_string($legalEntityId) ? $legalEntityId
            : (is_string($orgUnitId) ? DB::table('org_units')->where('id', $orgUnitId)->value('legal_entity_id') : null);
        if (! is_string($entity) || ! in_array($entity, $allowed, true)) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.out_of_scope')]]);
        }
    }

    /**
     * @return array<int, string>|null null = toda la organización
     */
    private function allowedEntities(Request $request, string $permission): ?array
    {
        $user = $request->user();

        return $user === null ? [] : $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, PositionRequest $model, bool $detail): array
    {
        $raw = $this->requests->present($model, $detail);
        // El solicitante ve el costo que él registró; los demás, solo con permiso de costos (y queda registrado).
        if ($model->requested_by_user_id === $this->userId($request) && ! $this->permissions($request)->has(self::BUDGET_PERMISSION)) {
            return $raw;
        }

        return $this->sensitive->present(self::SCHEMA, $raw, $this->permissions($request));
    }

    private function respond(Request $request, PositionRequest $model, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $model, true)], $status)->header('ETag', '"'.$model->lock_version.'"');
    }
}
