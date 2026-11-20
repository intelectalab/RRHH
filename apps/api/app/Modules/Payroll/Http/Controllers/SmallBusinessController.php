<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\SmallBusinessRegime;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * NOM-10 · Ventas y dotación anuales por razón social, estado del régimen micro o pequeña empresa
 * (acreditación, ventas en UIT contra el tope, permanencia) y cambio masivo de régimen con fecha efectiva.
 */
final class SmallBusinessController extends Controller
{
    public function __construct(private readonly SmallBusinessRegime $myp, private readonly DataScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $entity = isset($filter['legal_entity_id']) && is_string($filter['legal_entity_id']) ? $filter['legal_entity_id'] : null;
        if ($entity !== null) {
            $this->entityInScope($request, $entity, 'payroll.entity_annual_figures.view');
        }
        $allowed = DB::table('legal_entities');
        $this->scope->applyLegalEntity($allowed, $request, 'payroll.entity_annual_figures.view', 'id');
        $ids = $allowed->pluck('id')->map(fn ($v) => (string) $v)->all();
        $rows = array_values(array_filter($this->myp->list($entity), fn ($r) => in_array($r['legal_entity_id'], $ids, true)));

        return response()->json(['data' => $rows, 'meta' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, true);
        $this->entityInScope($request, $input['legal_entity_id'], 'payroll.entity_annual_figures.create');

        return response()->json(['data' => $this->myp->create($input)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->myp->show($id);
        $this->entityInScope($request, $row['legal_entity_id'], 'payroll.entity_annual_figures.view');

        return response()->json(['data' => $row]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = $this->myp->show($id);
        $this->entityInScope($request, $row['legal_entity_id'], 'payroll.entity_annual_figures.update');
        $input = $this->validated($request, false);

        return response()->json(['data' => $this->myp->update($id, $input)]);
    }

    public function status(Request $request, string $legalEntityId): JsonResponse
    {
        $this->entityInScope($request, $legalEntityId, 'payroll.entity_annual_figures.view');
        $asOf = $request->query('as_of');
        if ($asOf !== null && (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1)) {
            throw new AyniException('invalid_filter', 400, null, [['field' => 'as_of', 'rule' => 'date', 'message' => (string) trans('validation.date_format', ['attribute' => 'as_of', 'format' => 'AAAA-MM-DD'])]]);
        }

        return response()->json(['data' => $this->myp->status($legalEntityId, is_string($asOf) ? $asOf : null)]);
    }

    public function changeRegime(Request $request, string $legalEntityId): JsonResponse
    {
        $this->entityInScope($request, $legalEntityId, 'org.legal_entities.update');
        /** @var array{labor_regime_code: string, effective_on: string, reason: string, accreditation_status?: string|null, registry_number?: string|null, registered_on?: string|null} $input */
        $input = $request->validate([
            'labor_regime_code' => ['required', 'string', 'max:40'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'accreditation_status' => ['sometimes', 'nullable', 'in:not_required,pending,accredited'],
            'registry_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'registered_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->myp->changeEntityRegime($legalEntityId, $input)], 201);
    }

    /**
     * @return array{legal_entity_id: string, fiscal_year: int, net_sales: string, currency: string, uit_value: string, average_headcount?: string|null, source_document_id?: string|null}
     */
    private function validated(Request $request, bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $data = $request->validate([
            'legal_entity_id' => [$create ? 'required' : 'prohibited', 'uuid'],
            'fiscal_year' => [$create ? 'required' : 'prohibited', 'integer', 'between:2000,2100'],
            'net_sales' => [$req, 'array'],
            'net_sales.amount' => [$req, 'numeric', 'min:0', 'decimal:0,2'],
            'net_sales.currency' => [$req, 'string', 'size:3'],
            'uit_value' => [$req, 'array'],
            'uit_value.amount' => [$req, 'numeric', 'gt:0', 'decimal:0,2'],
            'uit_value.currency' => [$req, 'string', 'size:3'],
            'average_headcount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'source_document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        if (isset($data['net_sales'], $data['uit_value']) && $data['net_sales']['currency'] !== $data['uit_value']['currency']) {
            throw new AyniException('validation_failed', 422, null, [['field' => 'uit_value.currency', 'rule' => 'same', 'message' => (string) trans('payroll.myp.same_currency')]]);
        }
        $out = [];
        foreach (['legal_entity_id', 'fiscal_year', 'average_headcount', 'source_document_id'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $k === 'fiscal_year' ? (int) $data[$k] : ($data[$k] === null ? null : (string) $data[$k]);
            }
        }
        if (isset($data['net_sales'])) {
            $out['net_sales'] = (string) $data['net_sales']['amount'];
            $out['currency'] = (string) $data['net_sales']['currency'];
        }
        if (isset($data['uit_value'])) {
            $out['uit_value'] = (string) $data['uit_value']['amount'];
            $out['currency'] = (string) $data['uit_value']['currency'];
        }

        /** @var array{legal_entity_id: string, fiscal_year: int, net_sales: string, currency: string, uit_value: string, average_headcount?: string|null, source_document_id?: string|null} $out */
        return $out;
    }

    private function entityInScope(Request $request, string $legalEntityId, string $permission): void
    {
        $q = DB::table('legal_entities')->where('id', $legalEntityId);
        $this->scope->applyLegalEntity($q, $request, $permission, 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
    }
}
