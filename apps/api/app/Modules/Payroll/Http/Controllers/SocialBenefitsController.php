<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\SocialBenefits;
use App\Modules\Payroll\Infrastructure\Models\CtsDeposit;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Modules\Payroll\Infrastructure\Models\ProfitSharingDistribution;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\PermissionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * BSO-2 · Depósitos de CTS del proceso de CTS; BSO-3 · distribución anual de utilidades.
 *
 * Los montos (renta, remuneraciones, importes) son sensibles: solo viajan con payroll.results.view. El alcance por
 * empresa y por trabajador se aplica en la consulta; primero se ubica el recurso (404) y luego se validan los datos.
 */
final class SocialBenefitsController
{
    public function __construct(
        private readonly SocialBenefits $benefits,
        private readonly DataScope $scope,
        private readonly PermissionResolver $resolver,
    ) {}

    public function ctsDeposits(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = PayrollPeriod::query()->select('payroll_periods.*')->join('payroll_groups', 'payroll_groups.id', '=', 'payroll_periods.payroll_group_id')
            ->where('payroll_periods.id', $id);
        $this->scope->applyLegalEntity($query, $request, 'payroll.benefit_runs.view', 'payroll_groups.legal_entity_id');
        $p = $query->first();
        abort_unless($p instanceof PayrollPeriod, 404);
        $rows = $this->benefits->ctsDeposits($p, $this->visible($request, 'payroll.benefit_runs.view'), $this->can($request, 'payroll.results.view'));

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function deposit(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = CtsDeposit::query()->select('cts_deposits.*')->join('employments', 'employments.id', '=', 'cts_deposits.employment_id')
            ->where('cts_deposits.id', $id);
        $this->scope->applyEmployment($query, $request, 'payroll.benefit_runs.manage_cts', 'employments.id');
        $d = $query->first();
        abort_unless($d instanceof CtsDeposit, 404);
        $data = $request->validate([
            'deposited_on' => ['required', 'date_format:Y-m-d'],
            'deposit_reference' => ['required', 'string', 'max:120'],
        ]);

        return response()->json(['data' => $this->benefits->deposit($d, $data['deposited_on'], $data['deposit_reference'], $this->userId($request))]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ProfitSharingDistribution::query()->orderByDesc('fiscal_year');
        $this->scope->applyLegalEntity($query, $request, 'payroll.benefit_runs.view', 'profit_sharing_distributions.legal_entity_id');
        $entity = $request->input('filter.legal_entity_id');
        if (is_string($entity) && preg_match('/^[0-9a-f-]{36}$/i', $entity) === 1) {
            $query->where('legal_entity_id', $entity);
        }
        $year = $request->input('filter.fiscal_year');
        if (is_numeric($year)) {
            $query->where('fiscal_year', (int) $year);
        }
        $amounts = $this->can($request, 'payroll.results.view');
        $rows = $query->get()->map(fn (ProfitSharingDistribution $d): array => $this->benefits->presentDistribution($d, $amounts))->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'uuid'],
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'net_income_before_tax' => ['required', 'array'],
            'net_income_before_tax.amount' => ['required', 'numeric', 'decimal:0,2', 'gte:0'],
            'net_income_before_tax.currency' => ['sometimes', 'string', 'size:3'],
            'activity' => ['required', Rule::in(SocialBenefits::ACTIVITIES)],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $entity = DB::table('legal_entities')->where('legal_entities.id', $data['legal_entity_id']);
        $this->scope->applyLegalEntity($entity, $request, 'payroll.benefit_runs.configure_profit_sharing', 'legal_entities.id');
        abort_unless($entity->exists(), 422, (string) trans('payroll.benefits.unknown_entity'));
        $d = $this->benefits->createDistribution($data, $this->userId($request));

        return response()->json(['data' => $this->benefits->presentDistribution($d, true)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $d = $this->distribution($request, $id, 'payroll.benefit_runs.view');

        return response()->json(['data' => $this->benefits->presentDistribution($d, $this->can($request, 'payroll.results.view'),
            $this->visible($request, 'payroll.benefit_runs.view'), true)]);
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        $d = $this->distribution($request, $id, 'payroll.benefit_runs.configure_profit_sharing');
        $d = $this->benefits->calculate($d, $this->userId($request));

        return response()->json(['data' => $this->benefits->presentDistribution($d, true, null, true)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $d = $this->distribution($request, $id, 'payroll.benefit_runs.approve');
        $d = $this->benefits->approve($d, $this->userId($request));

        return response()->json(['data' => $this->benefits->presentDistribution($d, $this->can($request, 'payroll.results.view'))]);
    }

    private function distribution(Request $request, string $id, string $permission): ProfitSharingDistribution
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = ProfitSharingDistribution::query()->where('profit_sharing_distributions.id', $id);
        $this->scope->applyLegalEntity($query, $request, $permission, 'profit_sharing_distributions.legal_entity_id');
        $d = $query->first();
        abort_unless($d instanceof ProfitSharingDistribution, 404);

        return $d;
    }

    /** @return list<string> */
    private function visible(Request $request, string $permission): array
    {
        $query = DB::table('employments');
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
