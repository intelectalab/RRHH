<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\FifthCategoryLedger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * PER-11 · Libro de retenciones de quinta: movimientos (solo lectura; el libro solo admite inserciones) y
 * resumen del ejercicio por trabajador con su conciliación contra las boletas cerradas. Los montos son
 * sensibles: viajan con el permiso del libro y cada lectura queda en el registro de accesos (reglas 6 y 7).
 */
final class FifthCategoryLedgerController extends Controller
{
    public function __construct(
        private readonly FifthCategoryLedger $ledger,
        private readonly SensitiveFields $sensitive,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $q = $this->ledger->query($filter, $request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get();
        $items = $rows->map(fn ($r) => $this->ledger->present($r))->values()->all();
        $persons = $rows->map(fn ($r) => (string) DB::table('employments')->where('id', $r->employment_id)->value('person_id'))->values()->all();
        $data = $this->sensitive->presentMany('FifthCategoryLedgerEntry', $items, $this->permissions($request), 'api', $persons);

        return response()->json(['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->ledger->query([], $request)->where('g.id', $id)->first();
        if ($row === null) {
            throw new AyniException('not_found', 404);
        }
        $person = (string) DB::table('employments')->where('id', $row->employment_id)->value('person_id');

        return response()->json(['data' => $this->sensitive->present('FifthCategoryLedgerEntry', $this->ledger->present($row), $this->permissions($request), 'api', $person)]);
    }

    public function summary(Request $request, string $employmentId): JsonResponse
    {
        $q = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, 'payroll.fifth_category_ledger.view', 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
        /** @var array{fiscal_year: int|string} $input */
        $input = $request->validate(['fiscal_year' => ['required', 'integer', 'between:2000,2100']]);
        $person = (string) DB::table('employments')->where('id', $employmentId)->value('person_id');
        $data = $this->ledger->summary($employmentId, (int) $input['fiscal_year']);

        return response()->json(['data' => $this->sensitive->present('FifthCategorySummary', $data, $this->permissions($request), 'api', $person)]);
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
