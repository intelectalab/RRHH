<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\FormulaValidator;
use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Application\PayrollRegimes;
use App\Modules\Payroll\Domain\Engine\ContextVariables;
use App\Modules\Payroll\Domain\Formula\Parser;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * NOM-1 a NOM-5 · API del motor de nómina: regímenes (NOM-2), revisión de fórmulas (NOM-3), orden de cálculo
 * (NOM-4) y banco de pruebas que calcula una relación con su contexto y traza sin guardar nada (NOM-5).
 */
final class PayrollEngineController extends Controller
{
    private const BENCH = 'PayrollTestBench';

    /** Días máximos de un periodo del banco de pruebas (los del resumen de ausencias que lo alimenta). */
    private const MAX_DAYS = 93;

    public function __construct(
        private readonly PayrollRegimes $regimes,
        private readonly FormulaValidator $validator,
        private readonly PayrollEngine $engine,
        private readonly SensitiveFields $sensitive,
    ) {}

    public function regimes(): JsonResponse
    {
        $rows = $this->regimes->list();

        return response()->json(['data' => $rows, 'meta' => ['page' => 1, 'per_page' => count($rows), 'total' => count($rows)]]);
    }

    public function regime(string $id): JsonResponse
    {
        return response()->json(['data' => $this->regimes->show($id)]);
    }

    public function employmentRegime(Request $request, string $employmentId): JsonResponse
    {
        $this->assertInScope($request, $employmentId, 'payroll.regimes.view');
        $asOf = $this->date($request->query('as_of'), 'as_of') ?? CarbonImmutable::today()->toDateString();

        return response()->json(['data' => $this->regimes->forEmployment($employmentId, $asOf)]);
    }

    public function changeEmploymentRegime(Request $request, string $employmentId): JsonResponse
    {
        $this->assertInScope($request, $employmentId, 'people.employments.update');
        /** @var array{labor_regime_code: string, valid_from: string, reason: string} $input */
        $input = $request->validate([
            'labor_regime_code' => ['required', 'string', 'max:40'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->regimes->change($employmentId, $input)], 201);
    }

    public function validateFormula(Request $request): JsonResponse
    {
        /** @var array{formula: string, concept_code?: string|null, as_of?: string|null} $input */
        $input = $request->validate([
            'formula' => ['required', 'string', 'max:4000'],
            'concept_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $result = $this->validator->validate($input['formula'], $input['concept_code'] ?? null, $input['as_of'] ?? CarbonImmutable::today()->toDateString());

        return response()->json(['data' => $result + [
            'functions' => array_keys(Parser::FUNCTIONS), 'prefixes' => array_keys(Parser::PREFIXES), 'variables' => ContextVariables::ALL,
        ]]);
    }

    public function order(Request $request): JsonResponse
    {
        $asOf = $this->date($request->query('as_of'), 'as_of') ?? CarbonImmutable::today()->toDateString();
        $regime = is_string($request->query('regime')) && $request->query('regime') !== '' ? (string) $request->query('regime') : null;
        $kind = is_string($request->query('period_kind')) && $request->query('period_kind') !== '' ? (string) $request->query('period_kind') : null;
        $rows = $this->engine->order($asOf, $regime, $kind);

        return response()->json(['data' => $rows, 'meta' => ['as_of' => $asOf, 'total' => count($rows)]]);
    }

    public function bench(Request $request): JsonResponse
    {
        /** @var array{employment_id: string, from: string, to: string, period_kind?: string|null, variables?: array<string, string|bool>|null, incidents?: list<array{concept_code: string, amount?: string|null, quantity?: string|null}>|null} $input */
        $input = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'period_kind' => ['sometimes', 'nullable', 'string', 'in:regular,gratification,cts,profit_sharing,settlement,special'],
            'variables' => ['sometimes', 'nullable', 'array'],
            'incidents' => ['sometimes', 'nullable', 'array', 'max:200'],
            'incidents.*.concept_code' => ['required', 'string', 'max:60'],
            'incidents.*.amount' => ['sometimes', 'nullable', 'numeric'],
            'incidents.*.quantity' => ['sometimes', 'nullable', 'numeric'],
        ]);
        $this->assertInScope($request, $input['employment_id'], 'payroll.concepts.test');
        if (CarbonImmutable::parse($input['from'])->diffInDays(CarbonImmutable::parse($input['to'])) + 1 > self::MAX_DAYS) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.bench_too_long'), [['field' => 'to', 'rule' => 'max_days', 'message' => (string) trans('payroll.bench_too_long')]]);
        }
        $vars = [];
        foreach ((array) ($input['variables'] ?? []) as $k => $v) {
            if (! ContextVariables::has((string) $k)) {
                throw new AyniException('validation_failed', 422, (string) trans('payroll.formula.unknown_variable', ['name' => (string) $k]), [['field' => 'variables.'.$k, 'rule' => 'unknown', 'message' => (string) trans('payroll.formula.unknown_variable', ['name' => (string) $k])]]);
            }
            if (ContextVariables::ALL[(string) $k] === 'number' && ! is_numeric($v)) {
                throw new AyniException('validation_failed', 422, null, [['field' => 'variables.'.$k, 'rule' => 'numeric', 'message' => (string) trans('validation.numeric', ['attribute' => (string) $k])]]);
            }
            $vars[(string) $k] = is_bool($v) ? $v : (string) $v;
        }
        $incidents = [];
        foreach ((array) ($input['incidents'] ?? []) as $i) {
            $incidents[$i['concept_code']] = ['amount' => (string) ($i['amount'] ?? '0'), 'quantity' => (string) ($i['quantity'] ?? '0')];
        }
        $calc = $this->engine->calculate($input['employment_id'], $input['from'], $input['to'], $input['period_kind'] ?? 'regular', null, $vars, $incidents);
        $person = DB::table('employments')->where('id', $input['employment_id'])->value('person_id');
        $data = [
            'employment_id' => $calc['employment_id'], 'from' => $calc['from'], 'to' => $calc['to'], 'period_kind' => $calc['period_kind'],
            'status' => $calc['result']['status'], 'errors' => $calc['result']['errors'], 'warnings' => $calc['result']['warnings'],
            'order' => $calc['result']['order'], 'engine_version' => $calc['result']['engine_version'], 'pack_version' => $calc['result']['pack_version'],
            'lines' => $calc['result']['lines'], 'totals' => $calc['result']['totals'], 'context' => $calc['context'], 'saved' => false,
        ];

        return response()->json(['data' => $this->sensitive->present(self::BENCH, $data, $this->permissions($request), 'api', $person === null ? null : (string) $person)]);
    }

    private function assertInScope(Request $request, string $employmentId, string $permission): void
    {
        $q = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at');
        app(DataScope::class)->applyEmployment($q, $request, $permission, 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
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

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }
}
