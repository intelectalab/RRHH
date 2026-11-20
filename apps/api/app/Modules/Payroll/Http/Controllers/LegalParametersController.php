<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\LegalParameterCatalog;
use App\Modules\Payroll\Application\PayrollLegalTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * PAR-1 a PAR-7 · Parámetros legales, tablas por entidad y novedades normativas vistos por la organización.
 */
final class LegalParametersController extends Controller
{
    public function __construct(private readonly LegalParameterCatalog $catalog, private readonly PayrollLegalTables $tables) {}

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);

        return response()->json(['data' => $this->catalog->list($this->asOf($request), [
            'code' => isset($filter['code']) ? (string) $filter['code'] : null,
            'regime' => isset($filter['regime']) ? (string) $filter['regime'] : null,
            'pending' => filter_var($filter['pending'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'search' => $request->query('q') === null ? null : (string) $request->query('q'),
        ])]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->show($id)]);
    }

    public function addValue(Request $request, string $id): JsonResponse
    {
        $this->catalog->find($id);
        $data = $request->validate([
            'value' => ['required'], 'valid_from' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:500'],
            'legal_entity_id' => ['nullable', 'string'],
        ]);

        return response()->json(['data' => $this->catalog->addValue($id, $data, (string) $request->user()?->getAuthIdentifier())], 201);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $this->catalog->find($id);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->catalog->confirm($id, $data['note'] ?? null, (string) $request->user()?->getAuthIdentifier())]);
    }

    public function pensionFundRates(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tables->pensionFundRates($this->asOf($request))]);
    }

    public function fifthCategory(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tables->fifthCategory($this->asOf($request))]);
    }

    public function regimeParameters(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->tables->regimeParameters($id, $this->asOf($request), $request->query('legal_entity_id') === null ? null : (string) $request->query('legal_entity_id'))]);
    }

    public function wageTables(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->tables->wageTables($this->asOf($request))]);
    }

    public function wageTable(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->tables->wageTableById($id, $this->asOf($request))]);
    }

    public function verifyWageTable(Request $request, string $id): JsonResponse
    {
        $this->tables->wageTableById($id, now()->toDateString());
        $data = $request->validate(['acta_reference' => ['required', 'string', 'max:300'], 'note' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->tables->verifyWageTable($id, $data['acta_reference'], $data['note'] ?? null, (string) $request->user()?->getAuthIdentifier())]);
    }

    public function legalUpdates(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        foreach (['from', 'to'] as $key) {
            $v = $request->query($key);
            if ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) !== 1) {
                throw ValidationException::withMessages([$key => trans('payroll.parameters.invalid_date')]);
            }
        }

        return response()->json(['data' => $this->tables->legalUpdates([
            'legal_entity_id' => isset($filter['legal_entity_id']) ? (string) $filter['legal_entity_id'] : null,
            'regime' => isset($filter['regime']) ? (string) $filter['regime'] : null,
            'from' => $request->query('from') === null ? null : (string) $request->query('from'),
            'to' => $request->query('to') === null ? null : (string) $request->query('to'),
        ])]);
    }

    private function asOf(Request $request): string
    {
        $v = $request->query('as_of');
        if ($v === null) {
            return now()->toDateString();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) !== 1) {
            throw ValidationException::withMessages(['as_of' => trans('payroll.parameters.invalid_date')]);
        }

        return (string) $v;
    }
}
