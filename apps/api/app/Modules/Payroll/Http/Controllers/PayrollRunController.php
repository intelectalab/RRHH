<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\PayrollRuns;
use App\Modules\Payroll\Application\RetroactiveRecalculation;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * NOM-6 · NOM-7 · NOM-8 · Corridas de cálculo en segundo plano con su avance (abrir, reanudar, descartar),
 * resultado por relación con sus líneas y la explicación de cada monto, y recálculo retroactivo de una
 * relación. Los montos viajan solo con payroll.results.view y la explicación con payroll.results.trace.view
 * (regla 6); cada lectura de montos queda en el registro de accesos (regla 7).
 */
final class PayrollRunController extends Controller
{
    public function __construct(
        private readonly PayrollRuns $runs,
        private readonly RetroactiveRecalculation $retro,
        private readonly SensitiveFields $sensitive,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q = DB::table('payroll_runs AS r')->join('payroll_periods AS p', 'p.id', '=', 'r.payroll_period_id')
            ->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->orderByDesc('r.created_at')->orderByDesc('r.id');
        $this->scope->applyLegalEntity($q, $request, 'payroll.periods.view', 'g.legal_entity_id');
        $filter = (array) $request->query('filter', []);
        if (isset($filter['period_id']) && is_string($filter['period_id'])) {
            $q->where('r.payroll_period_id', $filter['period_id']);
        }
        if (isset($filter['status']) && is_string($filter['status'])) {
            $q->where('r.status', $filter['status']);
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $q)->count();
        $ids = $q->forPage($page, $perPage)->pluck('r.id');

        return response()->json(['data' => array_values($ids->map(fn ($id) => $this->runs->show((string) $id))->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var array{period_id: string, kind?: string|null, employment_ids?: list<string>|null, org_unit_id?: string|null} $input */
        $input = $request->validate([
            'period_id' => ['required', 'uuid'],
            'kind' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', PayrollRuns::KINDS)],
            'employment_ids' => ['sometimes', 'nullable', 'array', 'max:5000'],
            'employment_ids.*' => ['uuid'],
            'org_unit_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $kind = $input['kind'] ?? 'calculation';
        $permission = $kind === 'simulation' ? 'payroll.periods.simulate' : 'payroll.periods.calculate';
        if (! $this->permissions($request)->has($permission)) {
            throw new AyniException('forbidden', 403);
        }
        $this->periodInScope($request, $input['period_id'], $permission);
        $ids = $input['employment_ids'] ?? null;
        if ($ids !== null && $ids !== []) {
            $q = DB::table('employments')->whereIn('id', $ids)->whereNull('deleted_at');
            $this->scope->applyEmployment($q, $request, $permission, 'id');
            $ids = array_values($q->pluck('id')->map(fn ($v) => (string) $v)->all());
            if ($ids === []) {
                throw new AyniException('validation_failed', 422, null, [['field' => 'employment_ids', 'rule' => 'exists', 'message' => (string) trans('payroll.runs.no_employments')]]);
            }
        }
        $user = $request->user();
        $id = $this->runs->open($input['period_id'], ['employment_ids' => $ids, 'org_unit_id' => $input['org_unit_id'] ?? null], $kind,
            $user === null ? null : (string) $user->getAuthIdentifier());

        return response()->json(['data' => $this->runs->show($id)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->runInScope($request, $id, 'payroll.periods.view');

        return response()->json(['data' => $this->runs->show($id)]);
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $this->runInScope($request, $id, 'payroll.periods.calculate');
        $this->runs->resume($id);

        return response()->json(['data' => $this->runs->show($id)]);
    }

    public function discard(Request $request, string $id): JsonResponse
    {
        $this->runInScope($request, $id, 'payroll.periods.calculate');
        $this->runs->discard($id);

        return response()->json(['data' => $this->runs->show($id)]);
    }

    public function employments(Request $request, string $id): JsonResponse
    {
        $this->runInScope($request, $id, 'payroll.periods.view');
        $q = DB::table('payroll_run_employments')->where('payroll_run_id', $id);
        $this->scope->applyEmployment($q, $request, 'payroll.periods.view');
        $visible = array_values($q->pluck('employment_id')->map(fn ($v) => (string) $v)->all());
        $rows = $this->runs->employments($id, $visible);
        $people = array_map(fn ($r) => (string) $r['person_id'], $rows);
        $data = $this->sensitive->presentMany('PayrollRunEmployment', $rows, $this->permissions($request), 'api', $people);

        return response()->json(['data' => $data, 'meta' => ['page' => 1, 'per_page' => count($data), 'total' => count($data)]]);
    }

    public function lines(Request $request, string $id): JsonResponse
    {
        $employment = $this->runs->employmentOfRow($id);
        if ($employment === null) {
            throw new AyniException('not_found', 404);
        }
        $this->employmentInScope($request, $employment, 'payroll.results.view');
        $result = $this->runs->lines($id);
        $person = (string) DB::table('employments')->where('id', $employment)->value('person_id');
        $lines = $this->sensitive->presentMany('PayrollRunLine', $result['lines'], $this->permissions($request), 'api', array_fill(0, count($result['lines']), $person));

        return response()->json(['data' => $lines, 'meta' => ['run_employment' => $result['run_employment'], 'total' => count($lines)]]);
    }

    public function explanation(Request $request, string $id): JsonResponse
    {
        $employment = $this->runs->employmentOfLine($id);
        if ($employment === null) {
            throw new AyniException('not_found', 404);
        }
        $this->employmentInScope($request, $employment, 'payroll.results.trace.view');
        $person = (string) DB::table('employments')->where('id', $employment)->value('person_id');

        return response()->json(['data' => $this->sensitive->present('PayrollRunLine', $this->runs->explain($id), $this->permissions($request), 'api', $person)]);
    }

    public function retroactive(Request $request, string $employmentId): JsonResponse
    {
        $this->employmentInScope($request, $employmentId, 'payroll.periods.calculate');
        /** @var array{from: string, to?: string|null, preview?: bool|null, reason?: string|null} $input */
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'preview' => ['sometimes', 'nullable', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $preview = (bool) ($input['preview'] ?? false);
        $rows = $this->retro->recalculate($employmentId, $input['from'], $input['to'] ?? null, $preview, $input['reason'] ?? null);
        $person = (string) DB::table('employments')->where('id', $employmentId)->value('person_id');
        $data = $this->sensitive->presentMany('PayrollRetroactive', $rows, $this->permissions($request), 'api', array_fill(0, count($rows), $person));

        return response()->json(['data' => $data, 'meta' => ['employment_id' => $employmentId, 'preview' => $preview, 'total' => count($data)]]);
    }

    private function periodInScope(Request $request, string $periodId, string $permission): void
    {
        $q = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $periodId);
        $this->scope->applyLegalEntity($q, $request, $permission, 'g.legal_entity_id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
    }

    private function runInScope(Request $request, string $runId, string $permission): void
    {
        $period = DB::table('payroll_runs')->where('id', $runId)->value('payroll_period_id');
        if ($period === null) {
            throw new AyniException('not_found', 404);
        }
        $this->periodInScope($request, (string) $period, $permission);
    }

    private function employmentInScope(Request $request, string $employmentId, string $permission): void
    {
        $q = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, $permission, 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
