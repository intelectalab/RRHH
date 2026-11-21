<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\ParallelPayroll;
use App\Modules\Payroll\Application\PayrollObligations;
use App\Modules\Payroll\Infrastructure\Models\PayrollParallelComparison;
use App\Modules\Payroll\Infrastructure\Models\PayrollParallelDifference;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PER-13 · Planilla en paralelo (comparación con el sistema anterior) y CUM-9 · tablero de obligaciones de planilla.
 *
 * Primero se ubica el recurso dentro del alcance por empresa (404 si es de otra organización o está fuera); los
 * montos de las diferencias son sensibles (payroll.results.view, registrados en data_access_logs) y el alcance por
 * trabajador se aplica en la consulta (regla 6).
 */
final class ParallelPayrollController
{
    public function __construct(
        private readonly ParallelPayroll $parallel,
        private readonly PayrollObligations $obligations,
        private readonly DataScope $scope,
        private readonly PermissionResolver $resolver,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $p = $this->period($request, $id, 'payroll.periods.view');
        $rows = $this->parallel->list($p->id, $this->can($request, 'payroll.results.view'));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $p = $this->period($request, $id, 'payroll.periods.calculate');
        $data = $request->validate([
            'tolerance' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'legacy_system' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $tol = isset($data['tolerance']) ? (string) $data['tolerance'] : null;

        return response()->json(['data' => $this->parallel->compare($p->id, $tol, $data['legacy_system'] ?? null, $this->userId($request))], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $c = $this->comparison($request, $id, 'payroll.periods.view');
        $filter = $request->query('filter');
        $filter = is_array($filter) && is_string($filter['explained'] ?? null) ? ($filter['explained'] === '1' ? 'explained' : ($filter['explained'] === '0' ? 'unexplained' : null)) : null;
        $rows = $this->parallel->differences($c->id, $this->visible($request, 'payroll.periods.view'), $filter);
        $p = $request->attributes->get('effective_permissions');
        $rows = $this->sensitive->presentMany('PayrollParallelDifference', $rows, $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []), 'api',
            array_map(fn (array $r): string => (string) $r['person_id'], $rows));

        return response()->json(['data' => $this->parallel->present($c, $this->can($request, 'payroll.results.view')) + ['differences' => array_values($rows)]]);
    }

    public function export(Request $request, string $id): Response
    {
        $c = $this->comparison($request, $id, 'payroll.periods.view');
        abort_unless($this->can($request, 'payroll.results.view'), 403);

        return response($this->parallel->csv($c), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="paralelo-'.substr($c->id, 0, 8).'.csv"']);
    }

    public function markReady(Request $request, string $id): JsonResponse
    {
        $c = $this->comparison($request, $id, 'payroll.periods.calculate');
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->parallel->markReady($c, $data['note'] ?? null, $this->userId($request))]);
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        $c = $this->comparison($request, $id, 'payroll.periods.calculate');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->parallel->reopen($c, $data['reason'] ?? null)]);
    }

    public function explain(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $d = PayrollParallelDifference::query()->find($id);
        abort_unless($d instanceof PayrollParallelDifference, 404);
        $this->comparison($request, $d->payroll_parallel_comparison_id, 'payroll.periods.calculate');
        abort_unless(in_array($d->employment_id, $this->visible($request, 'payroll.periods.calculate'), true), 404);
        $data = $request->validate([
            'classification' => ['required', Rule::in(PayrollParallelDifference::CLASSIFICATIONS)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->parallel->explain($d, (string) $data['classification'], $data['comment'] ?? null, $this->userId($request))]);
    }

    public function obligations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'uuid'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $entity = $this->entity($request, (string) $data['legal_entity_id'], 'payroll.periods.view');
        $rows = $this->obligations->board($entity, $data['as_of'] ?? null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function assign(Request $request, string $code): JsonResponse
    {
        abort_unless(in_array($code, PayrollObligations::CODES, true), 404);
        $data = $request->validate([
            'legal_entity_id' => ['required', 'uuid'],
            'responsible_user_id' => ['required', 'uuid', Rule::exists('users', 'id')->whereNull('deleted_at')->where('status', 'active')],
        ]);
        $entity = $this->entity($request, (string) $data['legal_entity_id'], 'payroll.payroll_groups.update');

        return response()->json(['data' => $this->obligations->assign($entity, $code, (string) $data['responsible_user_id'])]);
    }

    private function entity(Request $request, string $id, string $permission): string
    {
        $q = DB::table('legal_entities')->whereNull('legal_entities.deleted_at')->where('legal_entities.id', $id);
        $this->scope->applyLegalEntity($q, $request, $permission, 'legal_entities.id');
        abort_unless($q->exists(), 404);

        return $id;
    }

    private function comparison(Request $request, string $id, string $permission): PayrollParallelComparison
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $c = PayrollParallelComparison::query()->find($id);
        abort_unless($c instanceof PayrollParallelComparison, 404);
        $this->period($request, $c->payroll_period_id, $permission);

        return $c;
    }

    private function period(Request $request, string $id, string $permission): PayrollPeriod
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = PayrollPeriod::query()->select('payroll_periods.*')->join('payroll_groups', 'payroll_groups.id', '=', 'payroll_periods.payroll_group_id')
            ->where('payroll_periods.id', $id);
        $this->scope->applyLegalEntity($query, $request, $permission, 'payroll_groups.legal_entity_id');
        $p = $query->first();
        abort_unless($p instanceof PayrollPeriod, 404);

        return $p;
    }

    /** @return list<string> */
    private function visible(Request $request, string $permission): array
    {
        $query = DB::table('employments')->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');

        return array_values($query->pluck('employments.id')->map(fn ($v): string => (string) $v)->all());
    }

    private function can(Request $request, string $permission): bool
    {
        $userId = $this->userId($request);

        return $userId !== null && $this->resolver->for($userId)->has($permission);
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }
}
