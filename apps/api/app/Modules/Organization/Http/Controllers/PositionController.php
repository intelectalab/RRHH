<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\PositionService;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Modules\Organization\Infrastructure\Models\PositionStatusEvent;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Query\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POS-1 · POS-2 · Posiciones como plazas: listado con alcance por empresa, ficha, edición, acciones de
 * estado (ME-03), historia de ocupantes, historial de estados y línea de reporte derivada.
 *
 * El costo presupuestado (budget) es sensible: sin org.positions.budget.view no viaja, no se filtra ni
 * ordena por él, y tampoco se puede registrar.
 */
final class PositionController
{
    private const FILTERS = ['status', 'legal_entity_id', 'org_unit_id', 'job_profile_id', 'site_id', 'cost_center_id', 'is_critical', 'is_shared'];

    private const SCHEMA = 'Position';

    private const BUDGET_PERMISSION = 'org.positions.budget.view';

    private const STATUSES = ['proposed', 'approved', 'open', 'recruiting', 'filled', 'frozen', 'closed'];

    public function __construct(
        private readonly PositionService $positions,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $permissions = $this->permissions($request);
        $this->sensitive->guardQuery(self::SCHEMA, $request, $permissions);
        $query = Position::query();
        $this->scope->applyLegalEntity($query, $request, 'org.positions.view');

        $status = TableQuery::filter($request, 'status');
        if ($status !== null) {
            $wanted = explode(',', $status);
            if (array_diff($wanted, self::STATUSES) !== []) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
            }
            $query->whereIn('status', $wanted);
        }
        foreach (['legal_entity_id', 'org_unit_id', 'job_profile_id', 'site_id', 'cost_center_id'] as $f) {
            $value = TableQuery::filter($request, $f);
            if ($value !== null) {
                if (preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
                    throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
                }
                $query->where($f, $value);
            }
        }
        foreach (['is_critical', 'is_shared'] as $f) {
            $value = TableQuery::filter($request, $f);
            if ($value !== null) {
                $query->where($f, in_array($value, ['true', '1'], true));
            }
        }
        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('title', 'ilike', $like)->orWhere('code', 'ilike', $like));
        }

        $page = (new TableQuery(
            ['code' => 'code', 'title' => 'title', 'status' => 'status', 'valid_from' => 'valid_from', 'seats' => 'seats',
                'budget' => 'budgeted_cost', 'created_at' => 'created_at', 'updated_at' => 'updated_at'],
            self::FILTERS,
        ))->page($query, $request, 'code');
        $models = Position::query()->whereIn('id', $page['ids'])->get()->keyBy('id');
        $ordered = [];
        foreach ($page['ids'] as $id) {
            $p = $models->get($id);
            if ($p instanceof Position) {
                $ordered[] = $p;
            }
        }

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, $this->positions->presentMany($ordered), $permissions),
            'meta' => $page['meta'],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->find($request, $id, 'org.positions.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));
        $this->guardBudget($request, $input);
        $this->guardEntityInScope($request, $input['legal_entity_id'], 'org.positions.create');

        return $this->respond($request, $this->positions->create($input, $this->userId($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $position = $this->find($request, $id, 'org.positions.update');
        $input = $request->validate($this->rules(false));
        $this->guardBudget($request, $input);
        if (array_key_exists('legal_entity_id', $input)) {
            $this->guardEntityInScope($request, $input['legal_entity_id'], 'org.positions.update');
        }
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respond($request, $this->positions->update($position, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'approve', 'org.positions.approve');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'reject', 'org.positions.approve');
    }

    public function openRecruitment(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'open-recruitment', 'org.positions.update');
    }

    public function cancelRecruitment(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'cancel-recruitment', 'org.positions.update');
    }

    public function freeze(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'freeze', 'org.positions.freeze');
    }

    public function unfreeze(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'unfreeze', 'org.positions.unfreeze');
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'close', 'org.positions.close');
    }

    /** Historia de ocupantes (POS-1), del más reciente al más antiguo; con as_of, los vigentes ese día. */
    public function occupants(Request $request, string $id): JsonResponse
    {
        $position = $this->find($request, $id, 'org.positions.view');
        $asOf = $request->query('as_of');
        if ($asOf !== null && (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false)) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }

        return response()->json(['data' => $this->positions->occupantHistory($position, is_string($asOf) ? $asOf : null)]);
    }

    /** Historial inalterable de estados (POS-2), del más reciente al más antiguo. */
    public function statusEvents(Request $request, string $id): JsonResponse
    {
        $position = $this->find($request, $id, 'org.positions.view');
        $events = PositionStatusEvent::query()->where('position_id', $position->id)->orderByDesc('created_at')->orderByDesc('id')->get();

        return response()->json(['data' => $events->map(fn (PositionStatusEvent $e) => $this->positions->presentEvent($e))->values()->all()]);
    }

    /** Línea de reporte derivada de la posición jefe o del responsable de la unidad (POS-1). */
    public function reportingLine(Request $request, string $id): JsonResponse
    {
        $position = $this->find($request, $id, 'org.positions.view');

        return response()->json(['data' => $this->positions->reportingLine($position)]);
    }

    /** Opciones activas para el formulario (sin pedir permisos de otros catálogos). */
    public function options(Request $request): JsonResponse
    {
        $entities = DB::table('legal_entities')->where('status', 'active')->whereNull('deleted_at');
        $allowed = $this->allowedEntities($request, 'org.positions.view');
        if ($allowed !== null) {
            $entities->whereIn('id', $allowed);
        }
        $pick = fn ($q, array $cols = ['id', 'name']) => $q->orderBy($cols[1])->get($cols)->map(fn ($r) => (array) $r)->values()->all();

        return response()->json(['data' => [
            'legal_entities' => $entities->orderBy('legal_name')->get(['id', 'legal_name as name'])->map(fn ($r) => (array) $r)->values()->all(),
            'job_profiles' => $pick(DB::table('job_profiles')->where('status', '<>', 'inactive')->whereNull('deleted_at'), ['id', 'name', 'code']),
            'org_units' => $pick(DB::table('org_units')->where('status', 'active')->whereNull('deleted_at'), ['id', 'name', 'legal_entity_id']),
            'sites' => $pick(DB::table('sites')->where('status', 'active')->whereNull('deleted_at'), ['id', 'name', 'legal_entity_id']),
            'cost_centers' => $pick(DB::table('cost_centers')->where('status', 'active')->whereNull('deleted_at'), ['id', 'name', 'legal_entity_id']),
            'positions' => $pick(Position::query()->toBase()->where('status', '<>', 'closed')->whereNull('deleted_at')
                ->when($allowed !== null, fn ($q) => $q->whereIn('legal_entity_id', $allowed ?? [])), ['id', 'title', 'code']),
        ]]);
    }

    private function transition(Request $request, string $id, string $action, string $permission): JsonResponse
    {
        $position = $this->find($request, $id, $permission);
        $data = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:500'],
            'effective_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $reason = $data['reason'] ?? $data['comment'] ?? null;

        return $this->respond($request, $this->positions->transition(
            $position, $action, is_string($reason) ? $reason : null,
            isset($data['effective_on']) ? (string) $data['effective_on'] : null, $this->userId($request),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'title' => [$req, 'string', 'min:2', 'max:150'],
            'job_profile_id' => [$req, 'uuid'],
            'legal_entity_id' => [$req, 'uuid'],
            'org_unit_id' => [$req, 'uuid'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'reports_to_position_id' => ['sometimes', 'nullable', 'uuid'],
            'salary_band_id' => ['sometimes', 'nullable', 'uuid'],
            'is_critical' => ['sometimes', 'boolean'],
            'fte' => ['sometimes', 'numeric', 'gt:0', 'max:1'],
            'is_shared' => ['sometimes', 'boolean'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'valid_from' => [$req, 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'budget' => ['sometimes', 'nullable', 'array:amount,currency'],
            'budget.amount' => ['required_with:budget', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'budget.currency' => ['required_with:budget', 'string', 'size:3', Rule::exists('currencies', 'code')],
        ];
    }

    /**
     * Sin permiso de costo no se registra el costo presupuestado (lo que la interfaz oculta, el backend lo valida).
     *
     * @param  array<string, mixed>  $input
     */
    private function guardBudget(Request $request, array $input): void
    {
        if (array_key_exists('budget', $input) && ! $this->permissions($request)->has(self::BUDGET_PERMISSION)) {
            throw ValidationException::withMessages(['budget' => [(string) trans('organization.positions.budget_forbidden')]]);
        }
    }

    private function find(Request $request, string $id, string $permission): Position
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = Position::query()->whereKey($id);
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null) {
            $query->whereIn('legal_entity_id', $allowed);
        }
        $position = $query->first();
        abort_unless($position instanceof Position, 404);

        return $position;
    }

    /**
     * @return array<int, string>|null null = toda la organización
     */
    private function allowedEntities(Request $request, string $permission): ?array
    {
        $user = $request->user();

        return $user === null ? [] : $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
    }

    /** Con alcance de empresa solo se crean o mueven posiciones a empresas del propio alcance. */
    private function guardEntityInScope(Request $request, mixed $legalEntityId, string $permission): void
    {
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null && (! is_string($legalEntityId) || ! in_array($legalEntityId, $allowed, true))) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.out_of_scope')]]);
        }
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

    private function respond(Request $request, Position $position, int $status = 200): JsonResponse
    {
        $data = $this->sensitive->present(self::SCHEMA, $this->positions->present($position), $this->permissions($request));

        return response()->json(['data' => $data], $status)->header('ETag', '"'.$position->lock_version.'"');
    }
}
