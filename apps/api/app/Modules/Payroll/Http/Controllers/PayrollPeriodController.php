<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\PayrollPeriods;
use App\Modules\Payroll\Application\PayrollTimeConsumption;
use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PER-1 · Grupos y periodos de planilla; PER-3 · consumo de tiempo con corte.
 *
 * El alcance por empresa se aplica en la consulta (regla 6). Primero se ubica el recurso (404 si es de otra
 * organización o fuera del alcance) y luego se validan los datos.
 */
final class PayrollPeriodController
{
    private const KINDS = ['regular', 'gratification', 'cts', 'profit_sharing', 'settlement', 'special'];

    public function __construct(
        private readonly PayrollPeriods $periods,
        private readonly PayrollTimeConsumption $time,
        private readonly DataScope $scope,
        private readonly PermissionResolver $resolver,
    ) {}

    public function groups(Request $request): JsonResponse
    {
        $query = PayrollGroup::query();
        $this->scope->applyLegalEntity($query, $request, 'payroll.periods.view');
        $rows = $query->orderBy('code')->get();

        return $this->list($rows->map(fn (PayrollGroup $g): array => $this->periods->presentGroup($g))->all());
    }

    public function group(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->periods->presentGroup($this->findGroup($request, $id, 'payroll.periods.view'))]);
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:200'],
            'legal_entity_id' => ['required', 'uuid'],
            'pay_frequency' => ['sometimes', Rule::in(['monthly', 'biweekly', 'weekly'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'labor_regime_code' => ['sometimes', 'nullable', 'string', 'exists:labor_regimes,code'],
            'payment_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'cutoff_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
        ]);
        $entity = DB::table('legal_entities')->where('legal_entities.id', $data['legal_entity_id']);
        $this->scope->applyLegalEntity($entity, $request, 'payroll.payroll_groups.update', 'legal_entities.id');
        abort_unless($entity->exists(), 404);

        return response()->json(['data' => $this->periods->presentGroup($this->periods->createGroup($data))], 201);
    }

    public function updateGroup(Request $request, string $id): JsonResponse
    {
        $g = $this->findGroup($request, $id, 'payroll.payroll_groups.update');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'labor_regime_code' => ['sometimes', 'nullable', 'string', 'exists:labor_regimes,code'],
            'payment_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'cutoff_day' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        return response()->json(['data' => $this->periods->presentGroup($this->periods->updateGroup($g, $data))]);
    }

    public function assignMember(Request $request, string $id): JsonResponse
    {
        $g = $this->findGroup($request, $id, 'payroll.payroll_groups.update');
        $data = $request->validate(['employment_id' => ['required', 'uuid'], 'valid_from' => ['required', 'date_format:Y-m-d']]);
        $employment = DB::table('employments')->where('employments.id', $data['employment_id'])->whereNull('employments.deleted_at')
            ->where('employments.legal_entity_id', $g->legal_entity_id);
        $this->scope->applyEmployment($employment, $request, 'payroll.payroll_groups.update', 'employments.id');
        abort_unless($employment->exists(), 404);
        $memberId = $this->periods->assignMember($g, (string) $data['employment_id'], (string) $data['valid_from']);

        return response()->json(['data' => ['id' => $memberId, 'payroll_group_id' => $g->id, 'employment_id' => $data['employment_id'], 'valid_from' => $data['valid_from']]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:payroll_group_id,legal_entity_id,status,kind,period_year'],
            'filter.payroll_group_id' => ['sometimes', 'uuid'],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.status' => ['sometimes', 'string'],
            'filter.kind' => ['sometimes', Rule::in(self::KINDS)],
            'filter.period_year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['payroll_group_id', 'legal_entity_id', 'status', 'kind', 'period_year']);
        }
        $query = PayrollPeriod::query()->select('payroll_periods.*')->join('payroll_groups', 'payroll_groups.id', '=', 'payroll_periods.payroll_group_id');
        $this->scope->applyLegalEntity($query, $request, 'payroll.periods.view', 'payroll_groups.legal_entity_id');
        $map = ['payroll_group_id' => 'payroll_periods.payroll_group_id', 'legal_entity_id' => 'payroll_groups.legal_entity_id', 'kind' => 'payroll_periods.kind', 'period_year' => 'payroll_periods.period_year'];
        foreach ($map as $f => $column) {
            $value = $request->input('filter.'.$f);
            if (is_string($value) || is_int($value)) {
                $query->where($column, $value);
            }
        }
        $status = $request->input('filter.status');
        if (is_string($status)) {
            $query->whereIn('payroll_periods.status', explode(',', $status));
        }
        $rows = $query->orderByDesc('payroll_periods.starts_on')->orderBy('payroll_periods.kind')->get();

        return $this->list($rows->map(fn (PayrollPeriod $p): array => $this->periods->present($p))->all());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->periods->present($this->find($request, $id, 'payroll.periods.view'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payroll_group_id' => ['required', 'uuid'],
            'kind' => ['sometimes', Rule::in(self::KINDS)],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_number' => ['required', 'integer', 'min:1', 'max:53'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'date_format:Y-m-d'],
            'payment_date' => ['sometimes', 'date_format:Y-m-d'],
            'attendance_from' => ['sometimes', 'date_format:Y-m-d'],
            'attendance_to' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $g = $this->findGroup($request, (string) $data['payroll_group_id'], 'payroll.periods.create');
        $userId = $this->userId($request);
        $canSkip = $userId !== null && $this->resolver->for($userId)->has('payroll.periods.reopen');

        return response()->json(['data' => $this->periods->present($this->periods->open($g, $data, $userId, $canSkip), $g)], 201);
    }

    public function population(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id, 'payroll.periods.view');
        $ids = $this->periods->population($p);
        $query = DB::table('employments as e')->join('people as pp', 'pp.id', '=', 'e.person_id')->whereIn('e.id', $ids);
        $this->scope->applyEmployment($query, $request, 'payroll.periods.view', 'e.id');
        $rows = $query->orderBy('pp.paternal_surname')->get(['e.id', 'e.employee_code', 'e.hire_date', 'e.termination_date',
            DB::raw("concat_ws(' ', pp.first_names, pp.paternal_surname, pp.maternal_surname) as person_name")]);

        return $this->list($rows->map(fn ($r): array => array_filter(['id' => (string) $r->id, 'employment_id' => (string) $r->id, 'person_name' => $r->person_name,
            'employee_code' => $r->employee_code, 'hire_date' => (string) $r->hire_date, 'termination_date' => $r->termination_date === null ? null : (string) $r->termination_date], fn ($v) => $v !== null))->all());
    }

    public function closeIncidents(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'close-incidents', 'payroll.periods.calculate');
    }

    public function reopenIncidents(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'reopen-incidents', 'payroll.periods.calculate');
    }

    /**
     * PER-4 · Calcular o recalcular el periodo (completo, unas relaciones o una unidad). La corrida corre en
     * segundo plano; el periodo queda «calculado» y last_run muestra su avance.
     */
    public function calculate(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id, 'payroll.periods.calculate');
        $data = $request->validate([
            'employment_ids' => ['sometimes', 'nullable', 'array', 'max:5000'], 'employment_ids.*' => ['uuid'],
            'org_unit_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $out = $this->periods->calculate($p, $this->userId($request), ['employment_ids' => $data['employment_ids'] ?? null, 'org_unit_id' => $data['org_unit_id'] ?? null]);

        return response()->json(['data' => $this->periods->present($out['period'])]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'submit', 'payroll.periods.submit');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'approve', 'payroll.periods.approve');
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'reject', 'payroll.periods.reject');
    }

    public function close(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'close', 'payroll.periods.close');
    }

    public function reopen(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'reopen', 'payroll.periods.reopen');
    }

    public function reopenCalculation(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'reopen-calculation', 'payroll.periods.calculate');
    }

    public function consumeTime(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id, 'payroll.incidents.create');

        return response()->json(['data' => $this->time->consume($p, $this->userId($request))]);
    }

    public function timeSummary(Request $request, string $id): JsonResponse
    {
        $p = $this->find($request, $id, 'payroll.periods.view');
        $query = DB::table('employments')->whereIn('employments.id', $this->periods->population($p));
        $this->scope->applyEmployment($query, $request, 'payroll.periods.view', 'employments.id');
        $ids = array_values($query->orderBy('employments.id')->limit(200)->pluck('employments.id')->map(fn ($v): string => (string) $v)->all());
        $rows = array_map(fn (array $r): array => ['id' => $r['employment_id']] + $r, $this->time->summary($p, $ids));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null,
            'from' => $p->cutoffFrom(), 'to' => $p->cutoffTo(), 'ends_on' => $p->day('ends_on')]]);
    }

    private function act(Request $request, string $id, string $action, string $permission): JsonResponse
    {
        $p = $this->find($request, $id, $permission);
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:1000'], 'comment' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $input['reason'] ??= $input['comment'] ?? null;

        return response()->json(['data' => $this->periods->present($this->periods->transition($p, $action, $this->userId($request), $input))]);
    }

    private function find(Request $request, string $id, string $permission): PayrollPeriod
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = PayrollPeriod::query()->select('payroll_periods.*')->join('payroll_groups', 'payroll_groups.id', '=', 'payroll_periods.payroll_group_id')
            ->where('payroll_periods.id', $id);
        $this->scope->applyLegalEntity($query, $request, $permission, 'payroll_groups.legal_entity_id');
        $p = $query->first();
        abort_unless($p instanceof PayrollPeriod, 404);

        return $p;
    }

    private function findGroup(Request $request, string $id, string $permission): PayrollGroup
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = PayrollGroup::query()->where('payroll_groups.id', $id);
        $this->scope->applyLegalEntity($query, $request, $permission, 'payroll_groups.legal_entity_id');
        $g = $query->first();
        abort_unless($g instanceof PayrollGroup, 404);

        return $g;
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    private function list(array $data): JsonResponse
    {
        return response()->json(['data' => array_values($data), 'meta' => ['total' => count($data), 'page' => 1, 'per_page' => max(1, count($data)), 'next_cursor' => null]]);
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }
}
