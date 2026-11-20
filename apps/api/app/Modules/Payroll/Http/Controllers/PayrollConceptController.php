<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\ConceptAccounting;
use App\Modules\Payroll\Application\PayrollConcepts;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Domain\Engine\ContextVariables;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CPT-1 a CPT-4 · API de conceptos de planilla: catálogo, borrador, revisión, probador, publicación con
 * vigencia, retiro, duplicado, afectaciones por base y contabilización por concepto.
 */
final class PayrollConceptController extends Controller
{
    /** Mismos permisos de campo que el banco de pruebas: montos y traza solo con payroll.results.view. */
    private const BENCH = 'PayrollTestBench';

    private const MAX_DAYS = 93;

    public function __construct(
        private readonly PayrollConcepts $concepts,
        private readonly ConceptAccounting $accounting,
        private readonly PayrollEngine $engine,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $asOf = $this->date($request->query('as_of'), 'as_of');
        $filter = (array) $request->query('filter', []);
        $rows = $this->concepts->list([
            'status' => is_string($filter['status'] ?? null) ? $filter['status'] : null,
            'kind' => is_string($filter['kind'] ?? null) ? $filter['kind'] : null,
            'search' => is_string($request->query('q')) ? (string) $request->query('q') : null,
            'as_of' => $asOf,
        ]);

        return response()->json(['data' => $rows, 'meta' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows)]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->concepts->show($id, $this->date($request->query('as_of'), 'as_of'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules(true));
        $this->assertMap($request, $input);

        return response()->json(['data' => $this->concepts->create($input, $this->userId($request))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->concepts->show($id);
        $input = $request->validate($this->rules(false));
        $this->assertMap($request, $input);

        return response()->json(['data' => $this->concepts->update($id, $input, $this->userId($request))]);
    }

    public function validateDraft(string $id): JsonResponse
    {
        return response()->json(['data' => $this->concepts->validateDraft($id)]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->concepts->publish($id, $this->userId($request))]);
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        $this->concepts->show($id);
        $input = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->concepts->retire($id, $input['reason'] ?? null)]);
    }

    public function duplicate(Request $request, string $id): JsonResponse
    {
        $this->concepts->show($id);
        $input = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => ['required', 'string', 'max:200'],
        ]);

        return response()->json(['data' => $this->concepts->duplicate($id, $input['code'], $input['name'], $this->userId($request))], 201);
    }

    /**
     * CPT-2 · Probador: calcula la relación con el concepto en borrador en lugar del vigente, sin guardar.
     */
    public function test(Request $request, string $id): JsonResponse
    {
        $candidate = $this->concepts->candidate($id);
        /** @var array{employment_id: string, from: string, to: string, period_kind?: string|null, variables?: array<string, string|bool>|null, incidents?: list<array{concept_code: string, amount?: string|null, quantity?: string|null}>|null} $input */
        $input = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period_kind' => ['sometimes', 'nullable', 'string', Rule::in(PayrollConcepts::PERIOD_KINDS)],
            'variables' => ['sometimes', 'nullable', 'array'],
            'incidents' => ['sometimes', 'nullable', 'array', 'max:200'],
            'incidents.*.concept_code' => ['required', 'string', 'max:60'],
            'incidents.*.amount' => ['sometimes', 'nullable', 'numeric'],
            'incidents.*.quantity' => ['sometimes', 'nullable', 'numeric'],
        ]);
        $q = DB::table('employments')->where('id', $input['employment_id'])->whereNull('deleted_at');
        app(DataScope::class)->applyEmployment($q, $request, 'payroll.concepts.test', 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
        if (CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) + 1 > self::MAX_DAYS) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.bench_too_long'), [['field' => 'to', 'rule' => 'max_days', 'message' => (string) trans('payroll.bench_too_long')]]);
        }
        $vars = [];
        foreach ((array) ($input['variables'] ?? []) as $k => $v) {
            if (ContextVariables::has((string) $k)) {
                $vars[(string) $k] = is_bool($v) ? $v : (string) $v;
            }
        }
        $incidents = [];
        foreach ((array) ($input['incidents'] ?? []) as $i) {
            $incidents[$i['concept_code']] = ['amount' => (string) ($i['amount'] ?? '0'), 'quantity' => (string) ($i['quantity'] ?? '0')];
        }
        $calc = $this->engine->calculate($input['employment_id'], $input['from'], $input['to'], $input['period_kind'] ?? 'regular', null, $vars, $incidents, $candidate);
        $r = $calc['result'];
        $lines = array_values(array_filter($r['lines'], fn ($l) => $l['concept_code'] === $candidate->code));
        // Las líneas de los conceptos que usó (directamente o por una base) acompañan al resultado.
        $used = array_keys((array) ($lines[0]['trace']['concepts'] ?? []));
        foreach ((array) ($lines[0]['trace']['bases'] ?? []) as $b) {
            $used = array_merge($used, array_keys((array) ($b['concepts'] ?? [])));
        }
        $deps = array_values(array_filter($r['lines'], fn ($l) => $l['concept_code'] !== $candidate->code && in_array($l['concept_code'], $used, true)));
        $data = [
            'concept_code' => $candidate->code, 'status' => $r['status'], 'errors' => $r['errors'], 'warnings' => $r['warnings'],
            'applied' => $lines !== [], 'order' => $r['order'], 'lines' => array_merge($lines, $deps), 'context' => $calc['context'], 'saved' => false,
        ];
        $person = DB::table('employments')->where('id', $input['employment_id'])->value('person_id');

        return response()->json(['data' => $this->sensitive->present(self::BENCH, $data, $this->permissions($request), 'api', $person === null ? null : (string) $person)]);
    }

    public function bases(): JsonResponse
    {
        $rows = $this->concepts->bases();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function matrix(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->concepts->matrix($this->date($request->query('as_of'), 'as_of'))]);
    }

    public function accountingCheck(Request $request): JsonResponse
    {
        $entity = $request->query('legal_entity_id');
        if (! is_string($entity) || preg_match('/^[0-9a-f-]{36}$/i', $entity) !== 1) {
            throw new AyniException('validation_failed', 422, null, [['field' => 'legal_entity_id', 'rule' => 'required', 'message' => (string) trans('validation.required', ['attribute' => 'legal_entity_id'])]]);
        }
        if (! DB::table('legal_entities')->where('id', $entity)->exists()) {
            throw new AyniException('not_found', 404);
        }
        $asOf = $this->date($request->query('as_of'), 'as_of') ?? CarbonImmutable::today()->toDateString();
        $missing = $this->accounting->missing($entity, $asOf);

        return response()->json(['data' => ['legal_entity_id' => $entity, 'as_of' => $asOf, 'complete' => $missing === [], 'missing' => $missing]]);
    }

    public function replaceAccounting(Request $request, string $id): JsonResponse
    {
        $this->concepts->show($id);
        /** @var array{valid_from: string, rules: list<array{legal_entity_id?: string|null, cost_center_id?: string|null, worker_category?: string|null, debit_account_id: string, credit_account_id: string}>} $input */
        $input = $request->validate([
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'rules' => ['present', 'array', 'max:200'],
            'rules.*.legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'rules.*.cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'rules.*.worker_category' => ['sometimes', 'nullable', Rule::in(ConceptAccounting::WORKER_CATEGORIES)],
            'rules.*.debit_account_id' => ['required', 'uuid'],
            'rules.*.credit_account_id' => ['required', 'uuid'],
        ]);

        return response()->json(['data' => $this->accounting->replaceRules($id, $input)]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $entity = $request->query('legal_entity_id');
        $rows = $this->accounting->accounts(is_string($entity) && $entity !== '' ? $entity : null);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows)]]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        /** @var array{code: string, name: string, kind: string, legal_entity_id?: string|null} $input */
        $input = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            'kind' => ['required', Rule::in(['expense', 'liability', 'asset', 'other'])],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid', 'exists:legal_entities,id'],
        ]);

        return response()->json(['data' => $this->accounting->createAccount($input)], 201);
    }

    public function updateAccount(Request $request, string $id): JsonResponse
    {
        $this->accounting->account($id);
        /** @var array{name?: string, kind?: string} $input */
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'kind' => ['sometimes', Rule::in(['expense', 'liability', 'asset', 'other'])],
        ]);

        return response()->json(['data' => $this->accounting->updateAccount($id, $input)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'code' => $create ? ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'] : ['prohibited'],
            'name' => [$req, 'string', 'max:200'],
            'kind' => $create ? ['required', Rule::in(['earning', 'deduction', 'employee_contribution', 'employer_contribution', 'tax', 'benefit', 'provision', 'informative'])] : ['prohibited'],
            'category' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_remunerative' => ['sometimes', 'boolean'],
            'print_on_payslip' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'statutory_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'formula' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'condition' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rounding' => ['sometimes', Rule::in(['none', 'half_up', 'down', 'up'])],
            'applies_to_regimes' => ['sometimes', 'nullable', 'array', 'max:20'],
            'applies_to_regimes.*' => ['string', 'max:40'],
            'applies_to_period_kinds' => ['sometimes', 'nullable', 'array'],
            'applies_to_period_kinds.*' => [Rule::in(PayrollConcepts::PERIOD_KINDS)],
            'affectations' => ['sometimes', 'array', 'max:20'],
            'affectations.*.base_code' => ['required', 'string', 'max:40'],
            'affectations.*.affects' => ['sometimes', 'boolean'],
            'affectations.*.projection_kind' => ['sometimes', 'nullable', Rule::in(PayrollConcepts::PROJECTION_KINDS)],
            'affectations.*.justification' => ['sometimes', 'nullable', 'string', 'max:500'],
            'valid_from' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['prohibited'],
        ];
    }

    /**
     * El código de la declaración es un mapeo: exige payroll.concepts.map además de crear o editar.
     *
     * @param  array<string, mixed>  $input
     */
    private function assertMap(Request $request, array $input): void
    {
        if (array_key_exists('statutory_code', $input) && $input['statutory_code'] !== null && ! $this->permissions($request)->has('payroll.concepts.map')) {
            throw new AyniException('forbidden', 403, null, [['field' => 'statutory_code', 'rule' => 'permission', 'message' => (string) trans('errors.forbidden')]]);
        }
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new AyniException('invalid_filter', 400, null, [['field' => $field, 'rule' => 'date', 'message' => (string) trans('validation.date_format', ['attribute' => $field, 'format' => 'AAAA-MM-DD'])]]);
        }

        return $value;
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user('ayni');

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
