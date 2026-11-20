<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Infrastructure\Models\PayrollOpeningBalance;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * PER-12 · Acumulados del ejercicio traídos del sistema anterior, para revisarlos contra el reporte de origen.
 * Solo lectura: entran y se revierten con su importación. El monto viaja con permiso de resultados de planilla.
 */
final class PayrollOpeningBalanceController extends Controller
{
    public function __construct(private readonly DataScope $scope, private readonly SensitiveFields $sensitive) {}

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $q = PayrollOpeningBalance::query()->orderBy('fiscal_year')->orderBy('employment_id')->orderBy('kind')->orderBy('period_month');
        $this->scope->applyEmployment($q, $request, 'payroll.incidents.view');
        foreach (['employment_id', 'kind', 'import_job_id'] as $f) {
            if (isset($filter[$f]) && is_string($filter[$f]) && $filter[$f] !== '') {
                $q->where($f, $filter[$f]);
            }
        }
        if (isset($filter['fiscal_year']) && is_numeric($filter['fiscal_year'])) {
            $q->where('fiscal_year', (int) $filter['fiscal_year']);
        }
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get();
        $items = $rows->map(fn (PayrollOpeningBalance $b): array => [
            'id' => $b->id, 'employment_id' => $b->employment_id, 'fiscal_year' => $b->fiscal_year, 'cutoff_date' => substr((string) $b->cutoff_date, 0, 10),
            'kind' => $b->kind, 'period_month' => $b->period_month, 'semester' => $b->semester, 'concept_code' => $b->concept_code,
            'amount' => ['amount' => (string) BigDecimal::of($b->amount)->toScale(2), 'currency' => $b->currency],
            'evidence' => $b->evidence, 'import_job_id' => $b->import_job_id,
        ])->values()->all();
        $persons = $rows->map(fn (PayrollOpeningBalance $b) => (string) DB::table('employments')->where('id', $b->employment_id)->value('person_id'))->values()->all();
        $p = $request->attributes->get('effective_permissions');
        $data = $this->sensitive->presentMany('PayrollOpeningBalance', $items, $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []), 'api', $persons);

        return response()->json(['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }
}
