<?php

namespace App\Modules\Payroll\Application\Settlements;

use App\Modules\Payroll\Application\PayrollEngine;
use App\Modules\Payroll\Domain\Engine\CalculationEngine;
use App\Modules\Payroll\Infrastructure\PayrollParameters;
use App\Shared\LegalParameters\LegalParameters;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * LIQ-1 · Calcula la liquidación de beneficios sociales con el motor de nómina y el tipo de periodo «settlement»: los
 * conceptos truncos (gratificación y su bonificación, CTS, vacaciones), las vacaciones ganadas sin gozar y su
 * indemnización si están vencidas, la indemnización por despido si se pidió (con el tope del régimen), la remuneración
 * pendiente si se pidió, los aportes, el ajuste final de quinta (RN-LIQ-11) y los saldos de préstamos y adelantos hasta
 * el tope de descuentos (RN-LIQ-05). Todo número legal sale de los parámetros con vigencia; cada línea lleva su traza
 * y una explicación en palabras. No guarda nada: Settlements y el simulador deciden qué hacer con el resultado.
 */
final class SettlementCalculator
{
    /** Concepto del paquete de país que representa cada saldo por descontar. */
    public const DEDUCTION_CONCEPTS = ['loan_balance' => 'dscto_prestamo', 'advance_balance' => 'dscto_adelanto'];

    /** Tipo de línea de la liquidación por concepto (lo demás se clasifica por el tipo del concepto). */
    private const LINE_KINDS = [
        'gratificacion_trunca' => 'gratification_truncated', 'bonif_extraordinaria_trunca' => 'extraordinary_bonus',
        'cts_trunca' => 'cts_truncated', 'vacaciones_truncas' => 'vacation_truncated', 'vacaciones_no_gozadas' => 'vacation_unused',
        'indemnizacion_vacacional' => 'vacation_indemnity', 'indemnizacion_despido' => 'severance_indemnity',
        'rem_basica' => 'pending_salary', 'asig_familiar' => 'pending_salary', 'rem_vacacional' => 'pending_salary',
        'dscto_prestamo' => 'loan_balance', 'dscto_adelanto' => 'advance_balance',
    ];

    private const PERCENT = 100;

    public function __construct(
        private readonly PayrollEngine $engine,
        private readonly LegalParameters $legal,
    ) {}

    /**
     * @param  array{severance_applies?: bool, include_pending_salary?: bool, deductions?: list<array{kind: string, amount: string, description?: string|null}>}  $inputs
     * @return array{status: string, termination_date: string, currency: string, regime: string|null, lines: list<array<string, mixed>>, totals: array<string, string>, periods: array<string, mixed>, errors: list<array<string, mixed>>, warnings: list<array<string, mixed>>, parameters: list<array<string, mixed>>, deductions: list<array<string, mixed>>, engine_version: string, pack_version: string|null}
     */
    public function calculate(string $employmentId, string $terminationDate, array $inputs): array
    {
        $overrides = ['liq_indemnizacion' => (bool) ($inputs['severance_applies'] ?? false), 'remuneracion_del_mes' => (bool) ($inputs['include_pending_salary'] ?? false)];
        $from = CarbonImmutable::parse($terminationDate)->startOfMonth()->toDateString();
        $first = $this->engine->calculate($employmentId, $from, $terminationDate, 'settlement', null, $overrides);
        $result = $first['result'];
        $context = $first['context'];
        $currency = (string) ($context['currency'] ?? 'PEN');
        $decimals = (int) ($result['lines'][0]['trace']['decimals'] ?? 2);

        // RN-LIQ-05 · Saldos de préstamos y adelantos hasta el tope del neto; el remanente queda documentado.
        $requested = array_values(array_filter($inputs['deductions'] ?? [], fn (array $d): bool => BigDecimal::of((string) $d['amount'])->isPositive()));
        $applied = [];
        $warnings = $result['warnings'] ?? [];
        if ($requested !== [] && ($result['status'] ?? '') === 'calculated') {
            $net = BigDecimal::max(BigDecimal::of((string) ($result['totals']['net'] ?? '0')), BigDecimal::zero());
            $entity = $this->legalEntity($employmentId);
            $params = new PayrollParameters($this->legal, (string) DB::table('legal_entities')->where('id', $entity)->value('country_code'),
                $context['regime'] ?? null, $entity, $terminationDate, $employmentId);
            $cap = $params->resolve('liquidacion_descuento_tope_pct');
            if ($cap === null || $cap['value'] === null) {
                $limit = $net;
                $warnings[] = ['code' => 'deduction_limit_unconfirmed', 'message' => (string) trans('payroll.settlements.warnings.deduction_limit_unconfirmed')];
            } else {
                $limit = $net->multipliedBy(BigDecimal::of((string) $cap['value']))->dividedBy(self::PERCENT, $decimals, RoundingMode::DOWN);
            }
            $incidents = [];
            foreach ($requested as $d) {
                $amount = BigDecimal::of((string) $d['amount']);
                $take = BigDecimal::min($amount, $limit);
                $limit = $limit->minus($take);
                $applied[] = ['kind' => $d['kind'], 'description' => $d['description'] ?? null, 'requested' => (string) $amount->toScale($decimals),
                    'applied' => (string) $take->toScale($decimals), 'remaining' => (string) $amount->minus($take)->toScale($decimals)];
                $code = self::DEDUCTION_CONCEPTS[$d['kind']];
                $incidents[$code] = ['amount' => (string) BigDecimal::of($incidents[$code]['amount'] ?? '0')->plus($take)];
            }
            if (array_filter($applied, fn (array $a): bool => BigDecimal::of($a['remaining'])->isPositive()) !== []) {
                $warnings[] = ['code' => 'deduction_capped', 'message' => (string) trans('payroll.settlements.warnings.deduction_capped')];
            }
            $incidents = array_filter($incidents, fn (array $i): bool => BigDecimal::of($i['amount'])->isPositive());
            if ($incidents !== []) {
                $second = $this->engine->calculate($employmentId, $from, $terminationDate, 'settlement', null, $overrides, $incidents);
                $result = $second['result'];
                $context = $second['context'];
                $warnings = array_merge($result['warnings'] ?? [], array_values(array_filter($warnings, fn (array $w): bool => str_starts_with((string) $w['code'], 'deduction_'))));
            }
        }

        $benefit = (array) ($context['sources']['benefit'] ?? []);
        foreach ((array) ($benefit['warnings'] ?? []) as $w) {
            $warnings[] = $w;
        }
        $lines = [];
        $parameters = [];
        foreach ($result['lines'] ?? [] as $line) {
            if (BigDecimal::of((string) $line['amount']['amount'])->isZero()) {
                continue;
            }
            $code = (string) $line['concept_code'];
            $kind = self::LINE_KINDS[$code] ?? match ((string) $line['kind']) {
                'deduction' => 'other_deduction',
                'tax' => 'tax',
                'employee_contribution', 'employer_contribution' => 'contribution',
                default => 'other_earning',
            };
            foreach ((array) ($line['trace']['parameters'] ?? []) as $p) {
                $parameters[($p['code'] ?? '').'@'.($p['valid_from'] ?? '')] = $p;
            }
            $lines[] = [
                'kind' => $kind, 'concept_code' => $code, 'concept_name' => (string) ($line['concept_name'] ?? $code), 'concept_kind' => (string) $line['kind'],
                'payroll_concept_id' => $line['concept_id'] ?? null, 'amount' => ['amount' => (string) $line['amount']['amount'], 'currency' => $currency],
                'quantity' => $this->quantity($kind, $benefit, $applied), 'explanation' => $this->explain($kind, $benefit, $applied, $line),
                'trace' => $line['trace'],
            ];
        }
        $totals = $result['totals'] ?? [];
        $status = (string) ($result['status'] ?? 'error');
        $errors = array_values($result['errors'] ?? []);
        if ($status === 'excluded') {
            $errors[] = ['code' => 'process_not_applicable', 'message' => (string) ($warnings[0]['message'] ?? trans('payroll.settlements.not_applicable'))];
        }

        return [
            'status' => $status === 'calculated' ? 'calculated' : 'error', 'termination_date' => $terminationDate, 'currency' => $currency,
            'regime' => $context['regime'] ?? null, 'lines' => $lines,
            'totals' => ['earnings' => (string) ($totals['earnings'] ?? '0'), 'deductions' => (string) ($totals['deductions'] ?? '0'),
                'net' => (string) ($totals['net'] ?? '0'), 'employer_contributions' => (string) ($totals['employer_contributions'] ?? '0'),
                'employer_cost' => (string) ($totals['employer_cost'] ?? '0')],
            'periods' => $benefit + ['fifth_category' => $result['income_tax'] ?? null],
            'errors' => $errors, 'warnings' => self::unique($warnings), 'parameters' => array_values($parameters), 'deductions' => $applied,
            'engine_version' => $result['engine_version'] ?? CalculationEngine::VERSION, 'pack_version' => $result['pack_version'] ?? null,
        ];
    }

    /**
     * RN-LIQ-02 · Plazo de pago desde el cese: parámetro del paquete de país (o el valor propio de la organización); sin
     * valor, la liquidación no tiene plazo y lo advierte.
     *
     * @return array{hours: string, due_at: string, due_on: string, pending_validation: bool}|null
     */
    public function deadline(string $employmentId, string $terminationDate, ?string $regime): ?array
    {
        $entity = $this->legalEntity($employmentId);
        $country = (string) DB::table('legal_entities')->where('id', $entity)->value('country_code');
        $p = (new PayrollParameters($this->legal, $country, $regime, $entity, $terminationDate, $employmentId))->resolve('liquidacion_plazo_pago_horas');
        if ($p === null || $p['value'] === null || ! is_numeric($p['value'])) {
            return null;
        }
        $hours = BigDecimal::of((string) $p['value']);
        $due = CarbonImmutable::parse($terminationDate, 'UTC')->addDay()->startOfDay()->addMinutes($hours->multipliedBy(60)->toScale(0, RoundingMode::UP)->toInt());

        return ['hours' => (string) $hours, 'due_at' => $due->toIso8601String(), 'due_on' => $due->toDateString(), 'pending_validation' => $p['pending_validation']];
    }

    private function legalEntity(string $employmentId): string
    {
        return (string) DB::table('employments')->where('id', $employmentId)->value('legal_entity_id');
    }

    /**
     * @param  array<string, mixed>  $benefit
     * @param  list<array<string, mixed>>  $applied
     */
    private function quantity(string $kind, array $benefit, array $applied): ?string
    {
        return match ($kind) {
            'gratification_truncated' => (string) ($benefit['gratification']['months'] ?? '0'),
            'vacation_unused' => (string) ($benefit['vacations']['pending_days'] ?? '0'),
            'vacation_indemnity' => (string) ($benefit['vacations']['overdue_days'] ?? '0'),
            default => null,
        };
    }

    /**
     * «Cada monto tiene su explicación» (LIQ-1): el periodo y el tiempo computable en palabras; la fórmula y los
     * parámetros con su vigencia van en la traza.
     *
     * @param  array<string, mixed>  $benefit
     * @param  list<array<string, mixed>>  $applied
     * @param  array<string, mixed>  $line
     */
    private function explain(string $kind, array $benefit, array $applied, array $line): string
    {
        $g = (array) ($benefit['gratification'] ?? []);
        $c = (array) ($benefit['cts'] ?? []);
        $v = (array) ($benefit['vacations'] ?? []);
        $s = (array) ($benefit['service'] ?? []);
        $sev = (array) ($benefit['severance'] ?? []);
        $vars = match ($kind) {
            'gratification_truncated', 'extraordinary_bonus' => ['from' => $g['from'] ?? '', 'to' => $g['to'] ?? '', 'months' => (string) ($g['months'] ?? 0)],
            'cts_truncated' => ['from' => $c['from'] ?? '', 'to' => $c['to'] ?? '', 'months' => (string) ($c['months'] ?? 0), 'days' => (string) ($c['days'] ?? 0),
                'gratification' => (string) ($c['gratification']['amount'] ?? '0')],
            'vacation_truncated' => ['from' => $v['from'] ?? '', 'to' => $v['to'] ?? '', 'months' => (string) ($v['months'] ?? 0), 'days' => (string) ($v['days'] ?? 0)],
            'vacation_unused' => ['days' => (string) ($v['pending_days'] ?? '0')],
            'vacation_indemnity' => ['days' => (string) ($v['overdue_days'] ?? '0')],
            'severance_indemnity' => ($sev['fixed_term'] ?? false)
                ? ['months' => (string) ($sev['remaining']['months'] ?? 0), 'days' => (string) ($sev['remaining']['days'] ?? 0)]
                : ['months' => (string) ($s['months'] ?? 0), 'days' => (string) ($s['days'] ?? 0)],
            'loan_balance', 'advance_balance' => $this->deductionVars($kind, $applied),
            default => ['concept' => (string) ($line['concept_name'] ?? $line['concept_code'])],
        };
        $key = $kind === 'severance_indemnity' && ($sev['fixed_term'] ?? false) ? 'severance_fixed_term' : $kind;

        return (string) trans('payroll.settlements.explain.'.$key, $vars);
    }

    /**
     * @param  list<array<string, mixed>>  $applied
     * @return array<string, string>
     */
    private function deductionVars(string $kind, array $applied): array
    {
        $req = BigDecimal::zero();
        $rem = BigDecimal::zero();
        foreach ($applied as $a) {
            if ($a['kind'] === $kind) {
                $req = $req->plus(BigDecimal::of($a['requested']));
                $rem = $rem->plus(BigDecimal::of($a['remaining']));
            }
        }

        return ['requested' => (string) $req, 'remaining' => (string) $rem];
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    private static function unique(array $warnings): array
    {
        $out = [];
        foreach ($warnings as $w) {
            $out[($w['code'] ?? '').'|'.($w['parameter'] ?? '')] = $w;
        }

        return array_values($out);
    }
}
