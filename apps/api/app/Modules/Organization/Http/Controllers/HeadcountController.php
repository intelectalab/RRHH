<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Organization\Application\HeadcountService;
use App\Modules\Organization\Infrastructure\Models\HeadcountBudget;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POS-4 · Tablero de dotación (aprobadas, ocupadas, vacantes, congeladas por unidad frente a su
 * presupuesto), desviación explicada por posición y presupuestos por unidad con vigencia.
 *
 * Los montos (costo proyectado, presupuestado y desviación) solo viajan con org.positions.budget.view
 * y cada lectura queda en el registro de accesos (SensitiveFields).
 */
final class HeadcountController
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $date = $this->date($request, ['as_of', 'legal_entity_id', 'org_unit_id']);
        $entity = $this->uuid($request, 'legal_entity_id', ['as_of', 'legal_entity_id', 'org_unit_id']);
        $unit = $this->uuid($request, 'org_unit_id', ['as_of', 'legal_entity_id', 'org_unit_id']);
        $board = $this->headcount->dashboard($date, $this->allowedEntities($request, 'org.positions.view'), $entity, $unit);
        $permissions = $this->permissions($request);
        $board['units'] = $this->sensitive->presentMany('HeadcountUnit', $board['units'], $permissions);
        if (! $permissions->has('org.positions.budget.view')) {
            unset($board['totals']['projected_cost'], $board['totals']['budgeted_cost'], $board['load_factor_pct']);
        }

        return response()->json(['data' => $board, 'meta' => ['as_of' => $date]]);
    }

    /** Desviación explicada por posición de una unidad. */
    public function positions(Request $request): JsonResponse
    {
        $date = $this->date($request, ['org_unit_id', 'as_of']);
        $unit = $this->uuid($request, 'org_unit_id', ['org_unit_id', 'as_of']);
        if ($unit === null) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['org_unit_id', 'as_of']);
        }
        $this->unitInScope($request, $unit, 'org.positions.view');
        $data = $this->headcount->explain($unit, $date);
        $data['positions'] = $this->sensitive->presentMany('HeadcountPosition', $data['positions'], $this->permissions($request));

        return response()->json(['data' => $data, 'meta' => ['as_of' => $date]]);
    }

    public function budgets(Request $request): JsonResponse
    {
        $query = HeadcountBudget::query();
        $allowed = $this->allowedEntities($request, 'org.headcount_plans.view');
        if ($allowed !== null) {
            $query->whereIn('legal_entity_id', $allowed);
        }
        $filters = ['org_unit_id', 'legal_entity_id', 'as_of'];
        foreach (['org_unit_id', 'legal_entity_id'] as $f) {
            $v = $this->uuid($request, $f, $filters);
            if ($v !== null) {
                $query->where($f, $v);
            }
        }
        if ($request->query('as_of') !== null) {
            $day = $this->date($request, $filters);
            $query->where('valid_from', '<=', $day)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
        }
        $rows = $query->orderBy('org_unit_id')->orderByDesc('valid_from')->limit(500)->get()
            ->map(fn (HeadcountBudget $b) => $this->headcount->presentBudget($b))->values()->all();

        return response()->json([
            'data' => $this->sensitive->presentMany('HeadcountBudget', $rows, $this->permissions($request)),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function showBudget(Request $request, string $id): JsonResponse
    {
        return $this->respondBudget($request, $this->findBudget($request, $id, 'org.headcount_plans.view'));
    }

    public function storeBudget(Request $request): JsonResponse
    {
        $input = $request->validate($this->budgetRules(true));
        $this->guardCost($request, $input);
        $this->unitInScope($request, (string) $input['org_unit_id'], 'org.headcount_plans.create');

        return $this->respondBudget($request, $this->headcount->createBudget($input), 201);
    }

    public function updateBudget(Request $request, string $id): JsonResponse
    {
        $budget = $this->findBudget($request, $id, 'org.headcount_plans.update');
        $input = $request->validate($this->budgetRules(false));
        $this->guardCost($request, $input);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');

        return $this->respondBudget($request, $this->headcount->updateBudget($budget, $input, $ifMatch === '' ? null : (int) $ifMatch));
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $rules = [
            'budgeted_positions' => [$req, 'integer', 'min:0', 'max:100000'],
            'monthly_cost' => ['sometimes', 'nullable', 'array:amount,currency'],
            'monthly_cost.amount' => ['required_with:monthly_cost', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'monthly_cost.currency' => ['required_with:monthly_cost', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
        if ($creating) {
            $rules += [
                'org_unit_id' => ['required', 'uuid'],
                'legal_entity_id' => ['sometimes', 'uuid'],
                'valid_from' => ['required', 'date_format:Y-m-d'],
            ];
        }

        return $rules;
    }

    /**
     * Sin permiso de costos no se registra el costo presupuestado (lo que la interfaz oculta, el backend lo valida).
     *
     * @param  array<string, mixed>  $input
     */
    private function guardCost(Request $request, array $input): void
    {
        if (array_key_exists('monthly_cost', $input) && ! $this->permissions($request)->has('org.positions.budget.view')) {
            throw ValidationException::withMessages(['monthly_cost' => [(string) trans('organization.positions.budget_forbidden')]]);
        }
    }

    private function findBudget(Request $request, string $id, string $permission): HeadcountBudget
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = HeadcountBudget::query()->whereKey($id);
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null) {
            $query->whereIn('legal_entity_id', $allowed);
        }
        $budget = $query->first();
        abort_unless($budget instanceof HeadcountBudget, 404);

        return $budget;
    }

    /** La unidad existe en esta organización y su empresa está en el alcance (404 si no). */
    private function unitInScope(Request $request, string $unitId, string $permission): void
    {
        $unit = DB::table('org_units')->where('id', $unitId)->whereNull('deleted_at')->first(['id', 'legal_entity_id']);
        if ($unit === null) {
            if ($request->isMethod('get')) {
                abort(404);
            }
            throw ValidationException::withMessages(['org_unit_id' => [(string) trans('organization.headcount.unknown_unit')]]);
        }
        $allowed = $this->allowedEntities($request, $permission);
        if ($allowed !== null && ($unit->legal_entity_id === null || ! in_array((string) $unit->legal_entity_id, $allowed, true))) {
            if ($request->isMethod('get')) {
                abort(404);
            }
            throw ValidationException::withMessages(['org_unit_id' => [(string) trans('organization.out_of_scope')]]);
        }
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function date(Request $request, array $allowed): string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null || $asOf === '') {
            return EffectiveDated::day(now());
        }
        if (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), $allowed);
        }

        return $asOf;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    private function uuid(Request $request, string $name, array $allowed): ?string
    {
        $value = $request->query($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), $allowed);
        }

        return $value;
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

    private function respondBudget(Request $request, HeadcountBudget $budget, int $status = 200): JsonResponse
    {
        $data = $this->sensitive->present('HeadcountBudget', $this->headcount->presentBudget($budget), $this->permissions($request));

        return response()->json(['data' => $data], $status)->header('ETag', '"'.$budget->lock_version.'"');
    }
}
