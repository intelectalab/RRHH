<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\AssignmentMovements;
use App\Modules\CoreHR\Application\EmploymentPresenter;
use App\Modules\CoreHR\Application\PersonnelActions;
use App\Modules\CoreHR\Http\Controllers\Concerns\ScopedAccess;
use App\Modules\CoreHR\Infrastructure\Models\PersonnelAction;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HIS-2 · HIS-4 · Movimientos de personal: registrar (con aprobación, programado o aplicado), listar los de
 * una relación o los programados y en aprobación de la organización, editar o anular lo programado y corregir
 * un movimiento aplicado. El alcance se aplica por la relación laboral (regla 6): fuera de él, 404.
 */
final class PersonnelActionController
{
    use ScopedAccess;

    private const STATUSES = ['draft', 'pending_approval', 'approved', 'scheduled', 'applied', 'rejected', 'cancelled', 'replaced'];

    public function __construct(
        private readonly PersonnelActions $actions,
        private readonly DataScope $scope,
    ) {}

    public function forEmployment(Request $request, string $employmentId): JsonResponse
    {
        $employment = $this->findEmployment($request, $employmentId, 'people.assignments.view');
        $rows = PersonnelAction::query()->where('employment_id', $employment->id)
            ->orderByDesc('effective_on')->orderByDesc('created_at')->get()
            ->map(fn (PersonnelAction $a) => $this->actions->present($a))->all();

        return response()->json(['data' => $rows, 'meta' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows), 'next_cursor' => null]]);
    }

    /**
     * Registra un movimiento. RRHH (people.assignments.create) lo aplica o programa según la fecha, salvo los tipos
     * que la organización somete a aprobación.
     */
    public function store(Request $request, string $employmentId): JsonResponse
    {
        return $this->register($request, $employmentId, true);
    }

    /** Un jefe (people.assignments.propose) propone un movimiento para su equipo: siempre pasa por aprobación. */
    public function propose(Request $request, string $employmentId): JsonResponse
    {
        return $this->register($request, $employmentId, false);
    }

    private function register(Request $request, string $employmentId, bool $canCreate): JsonResponse
    {
        $permissions = $this->permissions($request);
        $employment = $this->findEmployment($request, $employmentId, $canCreate ? 'people.assignments.create' : 'people.assignments.propose');
        $input = $request->validate($this->rules(true));
        $action = $this->actions->register($employment, [
            'kind' => $input['kind'] ?? null, 'effective_on' => $input['effective_on'], 'reason' => $input['reason'] ?? null,
            'changes' => $input['changes'],
        ], $this->userId($request), $canCreate, $permissions->has('people.assignments.correct'));

        return response()->json(['data' => $this->actions->present($action, true)], 201);
    }

    /** Movimientos de la organización dentro del alcance; por defecto los programados y en aprobación. */
    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        $filter = is_array($filter) ? $filter : [];
        $statuses = isset($filter['status']) && is_string($filter['status']) ? explode(',', $filter['status']) : PersonnelActions::OPEN_STATES;
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['filter[status]']);
        }
        $query = PersonnelAction::query()->whereIn('status', $statuses);
        foreach (['kind', 'employment_id'] as $field) {
            if (isset($filter[$field]) && is_string($filter[$field]) && $filter[$field] !== '') {
                $query->where($field, $filter[$field]);
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (isset($filter[$key])) {
                if (! is_string($filter[$key]) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter[$key]) !== 1) {
                    throw new InvalidFilterException((string) trans('errors.invalid_filter'), ["filter[{$key}]"]);
                }
                $query->where('effective_on', $op, $filter[$key]);
            }
        }
        $this->scope->applyEmployment($query, $request, 'people.assignments.view', 'personnel_actions.employment_id');
        $perPage = min(100, max(1, (int) $request->query('per_page', '25')));
        $page = max(1, (int) $request->query('page', '1'));
        $total = (clone $query)->count();
        $rows = $query->orderBy('effective_on')->orderBy('created_at')->forPage($page, $perPage)->get();
        $names = $this->employmentNames(array_values(array_unique($rows->map(fn (PersonnelAction $a): string => $a->employment_id)->all())));

        return response()->json([
            'data' => $rows->map(fn (PersonnelAction $a) => $this->actions->present($a) + $names[$a->employment_id])->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $action = $this->find($request, $id, 'people.assignments.view');

        return response()->json(['data' => $this->actions->present($action, true)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $action = $this->find($request, $id, 'people.assignments.create');
        $input = $request->validate($this->rules(false));

        return response()->json(['data' => $this->actions->present($this->actions->update($action, $input, $this->userId($request)), true)]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $action = $this->find($request, $id, 'people.assignments.cancel');
        $input = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json(['data' => $this->actions->present($this->actions->cancel($action, $input['reason'], $this->userId($request)))]);
    }

    /** RN-HIS-06 · Corrige un movimiento aplicado: devuelve el movimiento de corrección (el original queda reemplazado). */
    public function correct(Request $request, string $id): JsonResponse
    {
        $action = $this->find($request, $id, 'people.assignments.correct');
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            ...$this->changeRules(true),
        ]);

        return response()->json(['data' => $this->actions->present($this->actions->correct($action, $input['changes'], $input['reason'], $this->userId($request)))]);
    }

    private function find(Request $request, string $id, string $permission): PersonnelAction
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $action = PersonnelAction::query()->whereKey($id)->first();
        abort_unless($action instanceof PersonnelAction, 404);
        $this->findEmployment($request, $action->employment_id, $permission);

        return $action;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        return [
            'kind' => ['sometimes', 'nullable', Rule::in(AssignmentMovements::KINDS)],
            'effective_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            ...$this->changeRules($creating),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function changeRules(bool $required): array
    {
        $uuid = ['sometimes', 'nullable', 'uuid'];

        return [
            'changes' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'changes.job_profile_id' => ['sometimes', 'uuid'],
            'changes.org_unit_id' => ['sometimes', 'uuid'],
            'changes.site_id' => ['sometimes', 'uuid'],
            'changes.position_id' => $uuid,
            'changes.cost_center_id' => $uuid,
            'changes.manager_employment_id' => $uuid,
            'changes.work_modality' => ['sometimes', Rule::in(['onsite', 'remote', 'hybrid'])],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{person_name: string|null, employee_code: string|null}>
     */
    private function employmentNames(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $rows = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->whereIn('e.id', $ids)
            ->get(['e.id', 'e.employee_code', 'p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname']);
        foreach ($rows as $r) {
            $out[(string) $r->id] = ['person_name' => EmploymentPresenter::name($r), 'employee_code' => $r->employee_code];
        }
        foreach ($ids as $id) {
            $out[$id] ??= ['person_name' => null, 'employee_code' => null];
        }

        return $out;
    }
}
