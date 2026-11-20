<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\PayrollIncidents;
use App\Modules\Payroll\Infrastructure\Models\PayrollIncident;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PER-2 · Incidencias del periodo: listar, cargar, corregir, retirar (papelera), dar visto bueno y carga masiva.
 * Alcance por trabajador en la consulta; el periodo se ubica con el alcance de su empresa.
 */
final class PayrollIncidentController
{
    private const SOURCES = ['manual', 'import', 'attendance', 'absence', 'overtime', 'loan', 'advance', 'expense', 'retroactive', 'benefit', 'union', 'system'];

    public function __construct(private readonly PayrollIncidents $incidents, private readonly DataScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:payroll_period_id,employment_id,source,status'],
            'filter.payroll_period_id' => ['sometimes', 'uuid'],
            'filter.employment_id' => ['sometimes', 'uuid'],
            'filter.source' => ['sometimes', Rule::in(self::SOURCES)],
            'filter.status' => ['sometimes', Rule::in(['pending', 'approved', 'applied', 'rejected'])],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['payroll_period_id', 'employment_id', 'source', 'status']);
        }
        $query = PayrollIncident::query()->select('payroll_incidents.*');
        $this->scope->applyEmployment($query, $request, 'payroll.incidents.view', 'payroll_incidents.employment_id');
        foreach (['payroll_period_id', 'employment_id', 'source', 'status'] as $f) {
            $value = $request->input('filter.'.$f);
            if (is_string($value)) {
                $query->where('payroll_incidents.'.$f, $value);
            }
        }
        $rows = $query->orderBy('payroll_incidents.created_at')->orderBy('payroll_incidents.id')->limit(1000)->get();
        $data = $this->incidents->presentMany($rows);

        return response()->json(['data' => $data, 'meta' => ['total' => count($data), 'page' => 1, 'per_page' => max(1, count($data)), 'next_cursor' => null]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->one($this->find($request, $id, 'payroll.incidents.view'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $period = $this->period($request, (string) $data['payroll_period_id'], 'payroll.incidents.create');
        $this->assertEmploymentInScope($request, $data, 'payroll.incidents.create');

        return $this->one($this->incidents->create($period, $data, $this->userId($request)), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $i = $this->find($request, $id, 'payroll.incidents.update');
        $data = $request->validate($this->rules(false));
        $this->assertEmploymentInScope($request, $data, 'payroll.incidents.update');

        return $this->one($this->incidents->update($i, $data, $this->userId($request)));
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->incidents->delete($this->find($request, $id, 'payroll.incidents.delete'), $this->userId($request));

        return response()->noContent();
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $i = $this->find($request, $id, 'payroll.incidents.approve');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'comment' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->one($this->incidents->decide($i, 'approve', $this->userId($request), $data['reason'] ?? $data['comment'] ?? null));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $i = $this->find($request, $id, 'payroll.incidents.approve');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'comment' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return $this->one($this->incidents->decide($i, 'reject', $this->userId($request), $data['reason'] ?? $data['comment'] ?? null));
    }

    public function bulk(Request $request, string $id): JsonResponse
    {
        $period = $this->period($request, $id, 'payroll.incidents.create');
        $data = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:1000'], 'rows.*' => ['array']]);
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($data['rows']);
        $created = $this->incidents->bulk($period, $rows, $this->userId($request));

        return response()->json(['data' => ['created' => count($created), 'items' => $this->incidents->presentMany($created)]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'payroll_period_id' => [$req, 'uuid'],
            'employment_id' => [$create ? 'required_without:employee_code' : 'sometimes', 'uuid'],
            'employee_code' => ['sometimes', 'string', 'max:40'],
            'payroll_concept_id' => ['sometimes', 'uuid'],
            'concept_code' => ['sometimes', 'string', 'max:40'],
            'quantity' => ['sometimes', 'nullable', 'numeric'],
            'rate' => ['sometimes', 'nullable', 'numeric'],
            'amount' => ['sometimes', 'nullable'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /** El monto llega como número o como {amount, currency}; se normaliza al decimal. */
    private function one(PayrollIncident $i, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->incidents->presentMany([$i])[0]], $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertEmploymentInScope(Request $request, array &$data, string $permission): void
    {
        if (isset($data['amount']) && is_array($data['amount'])) {
            $data['amount'] = $data['amount']['amount'] ?? null;
        }
        if (! isset($data['employment_id']) || ! is_string($data['employment_id'])) {
            return;
        }
        $query = PayrollIncident::query()->getConnection()->table('employments')->where('employments.id', $data['employment_id']);
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        abort_unless($query->exists(), 404);
    }

    private function find(Request $request, string $id, string $permission): PayrollIncident
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = PayrollIncident::query()->select('payroll_incidents.*')->where('payroll_incidents.id', $id);
        $this->scope->applyEmployment($query, $request, $permission, 'payroll_incidents.employment_id');
        $i = $query->first();
        abort_unless($i instanceof PayrollIncident, 404);

        return $i;
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

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }
}
