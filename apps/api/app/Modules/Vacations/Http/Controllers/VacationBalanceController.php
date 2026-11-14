<?php

namespace App\Modules\Vacations\Http\Controllers;

use App\Modules\Vacations\Application\VacationAccrual;
use App\Modules\Vacations\Application\VacationLedger;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * VAC-2 · Saldos de vacaciones por relación laboral (RN-VAC-02: la suma del libro).
 *
 * - GET /vacation-balances: tabla por colaborador con disponible, vencidos y en devengo (alcance aplicado
 *   en la consulta; el empleado ve lo propio).
 * - GET /vacation-balances/{id}: {id} es la relación laboral; saldo con columnas, periodos y política.
 * - GET /vacation-balances/{id}/ledger: movimientos que explican el saldo.
 * - POST /vacation-balances/{id}/adjust: ajuste con motivo y sustento (RN-VAC-13).
 */
final class VacationBalanceController
{
    public function __construct(
        private readonly VacationLedger $ledger,
        private readonly VacationAccrual $accrual,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:legal_entity_id,status,overdue'],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.status' => ['sometimes', Rule::in(['active', 'suspended', 'terminating', 'terminated'])],
            'filter.overdue' => ['sometimes', Rule::in(['true', 'false'])],
            'q' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['legal_entity_id', 'status', 'overdue', 'q', 'page', 'per_page']);
        }
        $sums = DB::table('vacation_ledger_entries')->select('employment_id', DB::raw('sum(days) as available'))->groupBy('employment_id');
        $overdue = DB::table('vacation_accrual_periods')->select('employment_id', DB::raw('count(*) as overdue_periods'))->where('status', 'overdue')->groupBy('employment_id');
        $accruing = DB::table('vacation_accrual_periods')->select('employment_id', DB::raw('sum(accrued_days) as accruing_days'))->where('status', 'accruing')->groupBy('employment_id');
        $query = DB::table('employments as e')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->join('legal_entities as le', 'le.id', '=', 'e.legal_entity_id')
            ->leftJoinSub($sums, 's', 's.employment_id', '=', 'e.id')
            ->leftJoinSub($overdue, 'o', 'o.employment_id', '=', 'e.id')
            ->leftJoinSub($accruing, 'a', 'a.employment_id', '=', 'e.id')
            ->whereNull('e.deleted_at')
            ->whereIn('e.status', ['active', 'suspended', 'terminating', 'terminated']);
        $this->scope->applyEmployment($query, $request, 'time.vacation_balances.view', 'e.id');
        $entity = DataScope::selectedLegalEntity($request) ?? $request->input('filter.legal_entity_id');
        if (is_string($entity)) {
            $query->where('e.legal_entity_id', $entity);
        }
        $status = $request->input('filter.status');
        if (is_string($status)) {
            $query->where('e.status', $status);
        } else {
            $query->where('e.status', '!=', 'terminated');
        }
        if ($request->input('filter.overdue') === 'true') {
            $query->where('o.overdue_periods', '>', 0);
        }
        $q = $request->query('q');
        if (is_string($q) && trim($q) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($q)).'%';
            $query->where(fn ($w) => $w->where('e.employee_code', 'ilike', $like)
                ->orWhereRaw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) ilike ?", [$like]));
        }
        $total = (clone $query)->count();
        $perPage = (int) ($request->query('per_page', '25'));
        $page = (int) ($request->query('page', '1'));
        $rows = $query->orderBy('p.paternal_surname')->orderBy('p.first_names')->forPage($page, $perPage)
            ->get(['e.id', 'e.employee_code', 'e.status', 'e.hire_date', 'le.legal_name as legal_entity_name',
                DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as person_name"),
                's.available', 'o.overdue_periods', 'a.accruing_days'])
            ->map(fn ($r): array => [
                'id' => (string) $r->id,
                'employment_id' => (string) $r->id,
                'employee_code' => (string) $r->employee_code,
                'person_name' => trim((string) $r->person_name),
                'legal_entity_name' => (string) $r->legal_entity_name,
                'status' => (string) $r->status,
                'hire_date' => substr((string) $r->hire_date, 0, 10),
                'available_days' => (string) BigDecimal::of((string) ($r->available ?? '0'))->toScale(2),
                'accruing_days' => (string) BigDecimal::of((string) ($r->accruing_days ?? '0'))->toScale(2),
                'overdue_periods' => (int) ($r->overdue_periods ?? 0),
            ])->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'next_cursor' => null]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->employment($request, $id, 'time.vacation_balances.view');
        $asOf = $request->query('as_of');
        if ($asOf !== null && (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false)) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }

        return response()->json(['data' => ['id' => $id] + $this->ledger->balance($id, is_string($asOf) ? $asOf : null)]);
    }

    public function entries(Request $request, string $id): JsonResponse
    {
        $this->employment($request, $id, 'time.vacation_balances.view');
        $period = $request->query('period_id');
        if ($period !== null && (! is_string($period) || preg_match('/^[0-9a-f-]{36}$/i', $period) !== 1)) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['period_id']);
        }
        $this->accrual->sync($id);
        $rows = $this->ledger->entries($id, is_string($period) ? $period : null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function adjust(Request $request, string $id): JsonResponse
    {
        $this->employment($request, $id, 'time.vacation_balances.adjust');
        $data = $request->validate([
            'period_id' => ['sometimes', 'nullable', 'uuid'],
            'days' => ['required', 'numeric', 'regex:/^-?\d{1,3}(\.\d{1,2})?$/', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'evidence' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $user = $request->user();
        $this->ledger->adjust($id, $data['period_id'] ?? null, (string) $data['days'], $data['reason'], $data['evidence'], $user === null ? null : (string) $user->getAuthIdentifier());

        return response()->json(['data' => ['id' => $id] + $this->ledger->balance($id)]);
    }

    private function employment(Request $request, string $id, string $permission): void
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = DB::table('employments')->where('id', $id)->whereNull('deleted_at')->whereNotIn('status', ['pending', 'cancelled']);
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        abort_unless($query->exists(), 404);
    }
}
