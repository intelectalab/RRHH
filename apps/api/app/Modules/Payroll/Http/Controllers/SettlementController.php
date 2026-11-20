<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\Settlements\SettlementCalculator;
use App\Modules\Payroll\Application\Settlements\SettlementDocuments;
use App\Modules\Payroll\Application\Settlements\Settlements;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Tenancy\TenancyScope;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * LIQ-1 a LIQ-4 · Liquidaciones de beneficios sociales y su simulador. El alcance por trabajador se aplica en la
 * consulta (primero 404 y luego la validación); montos, líneas y totales son campos sensibles (no viajan sin permiso
 * y su lectura queda en el registro de accesos). Los estados cambian solo por acciones (ME-23).
 */
final class SettlementController
{
    private const SCHEMA = 'Settlement';

    private const MAX_SIMULATED_DATES = 2;

    public function __construct(
        private readonly Settlements $settlements,
        private readonly SettlementCalculator $calculator,
        private readonly SettlementDocuments $documents,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('settlements')->select('settlements.*')->whereNull('settlements.deleted_at')->where('settlements.is_simulation', false)
            ->orderByDesc('settlements.termination_date')->orderByDesc('settlements.id');
        $this->scope->applyEmployment($query, $request, 'payroll.settlements.view', 'settlements.employment_id');
        $status = $request->input('filter.status');
        if (is_string($status) && $status !== '') {
            $query->where('settlements.status', $status);
        }
        $employment = $request->input('filter.employment_id');
        if (is_string($employment) && preg_match('/^[0-9a-f-]{36}$/i', $employment) === 1) {
            $query->where('settlements.employment_id', $employment);
        }
        $rows = $query->limit(500)->get();
        $data = $this->sensitive->presentMany(self::SCHEMA, $rows->map(fn ($s): array => $this->settlements->present($s, true))->all(),
            $this->permissions($request), 'api', $rows->map(fn ($s) => $this->person((string) $s->employment_id))->all());

        return response()->json(['data' => array_values($data), 'meta' => ['total' => count($data), 'page' => 1, 'per_page' => max(1, count($data)), 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $employment = DB::table('employments')->where('employments.id', $data['employment_id'])->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($employment, $request, 'payroll.settlements.create', 'employments.id');
        abort_unless($employment->exists(), 422, (string) trans('payroll.settlements.unknown_employment'));
        $id = $this->settlements->create($data, $this->userId($request));

        return $this->respond($request, $id, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id, 'payroll.settlements.view');

        return $this->respond($request, $id);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $s = $this->find($request, $id, 'payroll.settlements.update');
        $data = $request->validate($this->rules(false));
        $this->settlements->update($s, $data);

        return $this->respond($request, $id);
    }

    public function calculate(Request $request, string $id): JsonResponse
    {
        $s = $this->find($request, $id, 'payroll.settlements.calculate');
        $this->settlements->calculate($s, $this->userId($request));

        return $this->respond($request, $id);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'payroll.settlements.calculate', 'submit');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'payroll.settlements.approve', 'approve');
    }

    public function return(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'payroll.settlements.approve', 'return', ['reason' => ['required', 'string', 'max:1000']]);
    }

    public function markPaid(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'payroll.settlements.mark_paid', 'mark-paid', [
            'paid_on' => ['sometimes', 'date_format:Y-m-d'], 'payment_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'beneficiary_reference' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
    }

    public function void(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'payroll.settlements.void', 'void', ['reason' => ['required', 'string', 'max:1000']]);
    }

    public function deliverDocuments(Request $request, string $id): JsonResponse
    {
        return $this->act($request, $id, 'payroll.settlements.mark_paid', 'deliver-documents', [
            'delivery_method' => ['sometimes', Rule::in(['portal', 'email', 'physical'])], 'confirm_physical_signature' => ['sometimes', 'boolean'],
        ]);
    }

    /** LIQ-4 · Genera los documentos que falten de la liquidación pagada y, si se pide, los envía a firma (DOC-6). */
    public function documents(Request $request, string $id): JsonResponse
    {
        $s = $this->find($request, $id, 'payroll.settlements.mark_paid');
        $data = $request->validate(['send_for_signature' => ['sometimes', 'boolean']]);
        if ($s->status !== 'paid') {
            throw new AyniException('invalid_transition', 409, (string) trans('payroll.settlements.documents.only_paid'));
        }
        $userId = (string) $this->userId($request);
        DB::transaction(function () use ($s, $data, $userId): void {
            $this->documents->generate((string) $s->id, $userId);
            if (($data['send_for_signature'] ?? false) === true) {
                $this->documents->sendForSignature((string) $s->id, $userId);
            }
        });

        return $this->respond($request, $id);
    }

    /**
     * LIQ-2 · RN-LIQ-09 · Simulación para una o dos fechas de cese hipotéticas: corre dentro de una transacción que se
     * deshace (no queda cese, liquidación ni aviso); solo queda el acceso sensible en el registro de accesos.
     */
    public function simulate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'termination_dates' => ['required', 'array', 'min:1', 'max:'.self::MAX_SIMULATED_DATES],
            'termination_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
            'severance_applies' => ['sometimes', 'boolean'], 'include_pending_salary' => ['sometimes', 'boolean'],
        ] + $this->deductionRules());
        $employment = DB::table('employments')->where('employments.id', $data['employment_id'])->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($employment, $request, 'payroll.settlements.simulate', 'employments.id');
        $emp = $employment->first(['employments.id', 'employments.hire_date', 'employments.person_id']);
        abort_unless($emp !== null, 422, (string) trans('payroll.settlements.unknown_employment'));
        foreach ($data['termination_dates'] as $date) {
            if ($date < substr((string) $emp->hire_date, 0, 10)) {
                abort(422, (string) trans('payroll.settlements.before_hire'));
            }
        }
        $inputs = ['severance_applies' => (bool) ($data['severance_applies'] ?? false), 'include_pending_salary' => (bool) ($data['include_pending_salary'] ?? false),
            'deductions' => array_values($data['deductions'] ?? [])];
        $results = [];
        DB::beginTransaction();
        try {
            foreach ($data['termination_dates'] as $date) {
                $r = $this->calculator->calculate((string) $emp->id, $date, $inputs);
                $results[] = $r + ['payment_deadline' => $this->calculator->deadline((string) $emp->id, $date, $r['regime'])];
            }
        } finally {
            DB::rollBack();
            TenancyScope::applyToDatabase(TenancyScope::requireTenant());
        }
        $scenarios = array_map(fn (array $r): array => [
            'termination_date' => $r['termination_date'], 'status' => $r['status'], 'lines' => array_map(fn (array $l): array => array_diff_key($l, ['payroll_concept_id' => 1]), $r['lines']),
            'totals' => $r['totals'], 'total' => ['amount' => $r['totals']['net'], 'currency' => $r['currency']], 'periods' => $r['periods'],
            'errors' => $r['errors'], 'warnings' => $r['warnings'], 'deductions' => $r['deductions'], 'payment_deadline' => $r['payment_deadline'],
        ], $results);
        $comparison = null;
        if (count($scenarios) === self::MAX_SIMULATED_DATES) {
            $comparison = ['net_difference' => ['amount' => (string) BigDecimal::of($scenarios[1]['totals']['net'])->minus($scenarios[0]['totals']['net']),
                'currency' => $results[0]['currency']]];
        }
        DB::table('data_access_logs')->insert([
            'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $this->userId($request), 'access_kind' => 'api', 'data_category' => 'salary',
            'subject_person_id' => $emp->person_id, 'entity_type' => 'SettlementSimulation', 'entity_id' => $emp->id,
            'fields' => '{lines,totals,total}', 'ip' => $request->ip(), 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);

        return response()->json(['data' => ['employment_id' => (string) $emp->id, 'simulated' => true, 'scenarios' => $scenarios, 'comparison' => $comparison]]);
    }

    /** @param array<string, mixed> $rules */
    private function act(Request $request, string $id, string $permission, string $action, array $rules = []): JsonResponse
    {
        $s = $this->find($request, $id, $permission);
        $input = $rules === [] ? [] : $request->validate($rules);
        $this->settlements->transition($s, $action, $this->userId($request), $input);

        return $this->respond($request, $id);
    }

    private function find(Request $request, string $id, string $permission): \stdClass
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = DB::table('settlements')->select('settlements.*')->where('settlements.id', $id)->whereNull('settlements.deleted_at');
        $this->scope->applyEmployment($query, $request, $permission, 'settlements.employment_id');
        $s = $query->first();
        abort_unless($s !== null, 404);

        return $s;
    }

    private function respond(Request $request, string $id, int $status = 200): JsonResponse
    {
        $s = DB::table('settlements')->where('id', $id)->first();
        abort_unless($s !== null, 404);
        $data = $this->sensitive->present(self::SCHEMA, $this->settlements->present($s, true, true), $this->permissions($request), 'api', $this->person((string) $s->employment_id));

        return response()->json(['data' => $data], $status);
    }

    /** @return array<string, mixed> */
    private function rules(bool $create): array
    {
        return ($create ? [
            'employment_id' => ['required', 'uuid'], 'termination_date' => ['required', 'date_format:Y-m-d'],
            'reason_code' => ['sometimes', 'nullable', 'string', 'max:60'],
        ] : []) + ['severance_applies' => ['sometimes', 'boolean'], 'include_pending_salary' => ['sometimes', 'boolean']] + $this->deductionRules();
    }

    /** @return array<string, mixed> */
    private function deductionRules(): array
    {
        return [
            'deductions' => ['sometimes', 'array', 'max:20'],
            'deductions.*.kind' => ['required', Rule::in(array_keys(SettlementCalculator::DEDUCTION_CONCEPTS))],
            'deductions.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'deductions.*.description' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    private function person(string $employmentId): ?string
    {
        $v = DB::table('employments')->where('id', $employmentId)->value('person_id');

        return $v === null ? null : (string) $v;
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }
}
