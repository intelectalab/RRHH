<?php

namespace App\Modules\Payroll\Infrastructure;

use App\Modules\Payroll\Domain\Engine\CalculationEngine;
use App\Modules\Payroll\Domain\Engine\ParameterResolver;
use App\Modules\Payroll\Domain\Tax\ProjectedAnnualWithholding;
use App\Modules\Payroll\Infrastructure\Country\DataPayrollCountryPack;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * PER-5 · Paso del motor que arma las entradas de la retención del impuesto a la renta del trabajador (quinta
 * categoría en Perú, kit/docs/18 §2.5) desde la planilla y el libro, llama a la función pura y deja el monto en
 * la línea del concepto de retención con su traza completa (proyección, impuesto anual, retenciones deducidas,
 * divisor y parámetros con vigencia).
 *
 * - R (ordinario del mes) y X (extraordinario del mes) salen de las líneas del cálculo con afectación a la base de
 *   renta según su naturaleza en la proyección (CPT-3); A, de las corridas vigentes de periodos anteriores del
 *   ejercicio; las retenciones previas, del libro (última secuencia de cada mes) y de las declaraciones juradas.
 * - G proyecta las gratificaciones ordinarias aún no pagadas del ejercicio con la remuneración del mes, el factor
 *   del régimen y su bonificación (por validar con el especialista: la gratificación del semestre podría usar
 *   la remuneración vigente al cierre del semestre).
 * - No domiciliado, exonerado o con otro empleador que retiene: sin proyección y con el motivo en el libro.
 * - Una incidencia manual en el concepto de retención prevalece (queda registrada como manual en la traza).
 */
final class IncomeTaxWithholding
{
    /**
     * @param  array<string, mixed>  $result  resultado del núcleo (estado calculado)
     * @param  array<string, mixed>  $variables  variables del contexto (sueldo, fechas, sistema de salud)
     * @return array<string, mixed> el resultado con la línea de retención calculada y la clave «income_tax» para el libro
     */
    public function apply(array $result, string $country, string $employmentId, ?string $periodId, string $from, string $to, string $periodKind,
        ?string $regime, array $variables, ParameterResolver $params, int $decimals): array
    {
        if (($result['status'] ?? '') !== 'calculated' || ! DataPayrollCountryPack::exists($country)) {
            return $result;
        }
        $tax = (new DataPayrollCountryPack($country))->incomeTax();
        if ($tax === null || ! in_array($periodKind, $tax['procesos'], true)) {
            return $result;
        }
        $index = null;
        foreach ($result['lines'] as $i => $l) {
            if ($l['concept_code'] === $tax['concepto']) {
                $index = $i;
            }
        }
        if ($index === null) {
            return $result;
        }
        $line = $result['lines'][$index];
        if (($line['trace']['source'] ?? '') === 'incidents' && ! BigDecimal::of($line['amount']['amount'])->isZero()) {
            $result['lines'][$index]['trace']['income_tax'] = ['reason' => 'manual'];

            return $result;
        }

        $resolved = [];
        $missing = [];
        foreach ($tax['parametros'] as $key => $code) {
            $p = $params->resolve($code);
            $resolved[$key] = $p;
            if (($p === null || $p['value'] === null) && $key !== 'tasa_no_domiciliado') {
                $missing[] = $code;
            }
        }
        if ($missing !== []) {
            $result['status'] = 'error';
            $result['lines'] = [];
            $result['totals'] = [];
            foreach ($missing as $code) {
                $result['errors'][] = ['code' => 'parameter_missing', 'concept' => $tax['concepto'], 'parameter' => $code,
                    'message' => (string) trans('payroll.parameter_missing', ['code' => $code])];
            }

            return $result;
        }

        $month = (int) substr($to, 5, 2);
        $year = (int) substr($to, 0, 4);
        $kinds = $this->projectionKinds($tax['base'], array_values(array_filter(array_map(fn ($l) => $l['concept_version_id'] ?? null, $result['lines']))));
        $ordinary = BigDecimal::zero();
        $extraordinary = BigDecimal::zero();
        $gratPaidNow = BigDecimal::zero();
        $taxable = BigDecimal::zero();
        foreach ($result['lines'] as $l) {
            $kind = $kinds[(string) ($l['concept_version_id'] ?? '')] ?? null;
            if ($kind === null) {
                continue;
            }
            $a = BigDecimal::of($l['amount']['amount']);
            $taxable = $taxable->plus($a);
            match ($kind) {
                'extraordinary' => $extraordinary = $extraordinary->plus($a),
                'gratification' => $gratPaidNow = $gratPaidNow->plus($a),
                default => $ordinary = $ordinary->plus($a),
            };
        }
        $profile = DB::table('peruvian_employee_profiles')->where('employment_id', $employmentId);
        EffectiveDated::applyAsOf($profile, $to);
        $profile = $profile->first(['is_tax_resident', 'fifth_category_exempt']);
        $declarations = DB::table('external_income_declarations')->where('employment_id', $employmentId)->where('fiscal_year', $year)
            ->where('status', 'validated')->get(['kind', 'gross_income', 'exempt_income', 'tax_withheld', 'monthly_detail', 'this_employer_withholds']);
        $received = BigDecimal::zero();
        $projectedExternal = BigDecimal::zero();
        $withholds = true;
        $withheld = $this->ledgerWithholdings($employmentId, $year);
        foreach ($declarations as $d) {
            if ($d->kind === 'previous_employer') {
                $received = $received->plus((string) ($d->gross_income ?? '0'))->minus((string) ($d->exempt_income ?? '0'));
                $detail = json_decode((string) ($d->monthly_detail ?? 'null'), true);
                if (is_array($detail) && $detail !== []) {
                    foreach ($detail as $m => $row) {
                        $withheld[(int) $m] = BigDecimal::of($withheld[(int) $m] ?? '0')->plus((string) ($row['withheld'] ?? '0'));
                    }
                } else {
                    // RN-PE-QTA-05 · Sin detalle mensual se deducen completas desde el primer cálculo (política «total»; por validar).
                    $withheld[0] = BigDecimal::of($withheld[0] ?? '0')->plus((string) ($d->tax_withheld ?? '0'));
                }
            } elseif ($d->this_employer_withholds === false) {
                $withholds = false;
            } else {
                $projectedExternal = $projectedExternal->plus(BigDecimal::of((string) ($d->gross_income ?? '0'))->multipliedBy(13 - $month));
            }
        }

        $input = [
            'month' => $month, 'termination' => $periodKind === $tax['proceso_ajuste_final'],
            'resident' => $profile === null ? true : (bool) $profile->is_tax_resident, 'exempt' => $profile !== null && (bool) $profile->fifth_category_exempt,
            'this_employer_withholds' => $withholds,
            'ordinary_month' => (string) $ordinary,
            'pending_gratifications' => (string) $this->pendingGratifications($tax, $params, $month, $year, $ordinary, $gratPaidNow, $variables, $regime, $decimals),
            'prior_income' => (string) $this->priorIncome($tax['base'], $employmentId, $periodId, $year, $month),
            'external_received' => (string) $received, 'external_projected' => (string) $projectedExternal,
            'extraordinary_month' => (string) $extraordinary, 'taxable_month_total' => (string) $taxable,
            'withholdings_by_month' => array_map(fn ($v) => (string) $v, $withheld),
        ];
        $val = static function (string $key) use ($resolved): ?string {
            $r = $resolved[$key] ?? null;

            return $r === null ? null : $r['value'];
        };
        $p = [
            'uit' => (string) $val('uit'), 'deduction_uit' => (string) $val('deduccion_uit'),
            'scale' => self::scale($val('escala')), 'divisors' => self::numbers($val('divisores')),
            'cutoffs' => self::numbers($val('cortes')), 'non_resident_rate_pct' => $val('tasa_no_domiciliado'), 'decimals' => $decimals,
        ];
        $calc = ProjectedAnnualWithholding::calculate($input, $p);
        if (($calc['withholding'] ?? null) === null) {
            $result['status'] = 'error';
            $result['lines'] = [];
            $result['totals'] = [];
            $result['errors'][] = ['code' => 'parameter_missing', 'concept' => $tax['concepto'], 'parameter' => $tax['parametros']['tasa_no_domiciliado'],
                'message' => (string) trans('payroll.parameter_missing', ['code' => $tax['parametros']['tasa_no_domiciliado']])];

            return $result;
        }
        $amount = BigDecimal::of($calc['withholding']);
        $refund = $amount->isNegative() ? (string) $amount->abs() : null;
        // RN-PE-QTA-11/14 · El exceso no se descuenta en negativo: queda en el libro y como devolución pendiente.
        $lineAmount = $amount->isNegative() ? BigDecimal::zero()->toScale($decimals) : $amount;
        $used = array_values(array_filter(array_map(fn ($r) => $r === null ? null : ['code' => $r['code'], 'value' => $r['value'], 'valid_from' => $r['valid_from'],
            'pending_validation' => $r['pending_validation'], 'origin' => $r['origin']], $resolved)));
        $result['lines'][$index]['amount']['amount'] = (string) $lineAmount;
        $result['lines'][$index]['trace'] = array_merge($line['trace'], ['source' => 'income_tax', 'income_tax' => $calc + ['refund' => $refund], 'parameters' => $used]);
        if ($refund !== null) {
            $result['warnings'][] = ['code' => 'income_tax_refund', 'amount' => $refund, 'message' => (string) trans('payroll.income_tax_refund', ['amount' => $refund])];
        }
        $result['totals'] = CalculationEngine::totals($result['lines'], $decimals);
        $result['income_tax'] = ['fiscal_year' => $year, 'month' => $month, 'currency' => $line['amount']['currency'], 'input' => $input, 'parameters' => $p, 'result' => $calc,
            'parameters_used' => $used];

        return $result;
    }

    /**
     * Naturaleza en la proyección (ordinario, gratificación, extraordinario) de las versiones que afectan la base.
     *
     * @param  list<string>  $versionIds
     * @return array<string, string>
     */
    private function projectionKinds(string $base, array $versionIds): array
    {
        if ($versionIds === []) {
            return [];
        }

        return DB::table('payroll_concept_affectations')->whereIn('payroll_concept_version_id', $versionIds)->where('base_code', $base)->where('affects', true)
            ->get(['payroll_concept_version_id', 'projection_kind'])
            ->mapWithKeys(fn ($a) => [(string) $a->payroll_concept_version_id => (string) ($a->projection_kind ?? 'ordinary')])->all();
    }

    /**
     * A · Ingresos afectos puestos a disposición en meses anteriores del ejercicio: líneas de la última corrida
     * vigente (no simulación ni descartada) de cada periodo anterior con fecha de pago en el ejercicio.
     */
    private function priorIncome(string $base, string $employmentId, ?string $periodId, int $year, int $month): BigDecimal
    {
        $sum = DB::table('payroll_run_lines AS l')
            ->join('payroll_run_employments AS re', 're.id', '=', 'l.payroll_run_employment_id')
            ->join('payroll_runs AS r', 'r.id', '=', 'l.payroll_run_id')
            ->join('payroll_periods AS p', 'p.id', '=', 'r.payroll_period_id')
            ->join('payroll_concept_affectations AS a', fn ($j) => $j->on('a.payroll_concept_version_id', '=', 'l.payroll_concept_version_id')
                ->where('a.base_code', $base)->where('a.affects', true))
            ->where('re.employment_id', $employmentId)->where('re.status', 'calculated')
            ->when($periodId !== null, fn ($q) => $q->where('p.id', '<>', $periodId))
            ->whereRaw('EXTRACT(YEAR FROM COALESCE(p.payment_date, p.ends_on)) = ?', [$year])
            ->whereRaw('EXTRACT(MONTH FROM COALESCE(p.payment_date, p.ends_on)) < ?', [$month])
            ->whereRaw("r.run_number = (SELECT max(r2.run_number) FROM payroll_runs r2 WHERE r2.payroll_period_id = p.id AND r2.kind <> 'simulation' AND r2.status IN ('completed','approved','closed'))")
            ->sum('l.amount');

        return BigDecimal::of((string) ($sum ?: '0'));
    }

    /**
     * P · Retenciones propias del ejercicio por mes: la última secuencia de cada mes de corridas no descartadas.
     *
     * @return array<int, BigDecimal>
     */
    private function ledgerWithholdings(string $employmentId, int $year): array
    {
        $rows = DB::table('fifth_category_ledger AS g')->leftJoin('payroll_runs AS r', 'r.id', '=', 'g.payroll_run_id')
            ->where('g.employment_id', $employmentId)->where('g.fiscal_year', $year)
            ->where(fn ($q) => $q->whereNull('g.payroll_run_id')->orWhereNotIn('r.status', ['discarded']))
            ->orderBy('g.period_month')->orderBy('g.id')->get(['g.period_month', 'g.entry_kind', 'g.withholding_amount']);
        $out = [];
        $byKind = [];
        foreach ($rows as $r) {
            // La retención mensual y su corrección son el mismo cálculo del mes: cuenta la última de las dos.
            $group = in_array($r->entry_kind, ['monthly', 'correction'], true) ? 'monthly' : (string) $r->entry_kind;
            $byKind[(int) $r->period_month][$group] = (string) $r->withholding_amount;
        }
        foreach ($byKind as $m => $kinds) {
            $total = BigDecimal::zero();
            foreach ($kinds as $amount) {
                $total = $total->plus($amount);
            }
            $out[$m] = $total;
        }

        return $out;
    }

    /**
     * G · Gratificaciones ordinarias del ejercicio aún no pagadas (mes de pago ≥ mes del cálculo), con la
     * remuneración del mes, el factor del régimen, los meses completos del semestre y su bonificación.
     *
     * @param  array<string, mixed>  $tax
     * @param  array<string, mixed>  $variables
     */
    private function pendingGratifications(array $tax, ParameterResolver $params, int $month, int $year, BigDecimal $ordinary, BigDecimal $paidNow,
        array $variables, ?string $regime, int $decimals): BigDecimal
    {
        $g = $tax['gratificaciones'];
        $applies = $params->resolve($g['aplica']);
        if ($applies !== null && $applies['value'] !== null && in_array(strtolower((string) $applies['value']), ['0', 'false', 'no'], true)) {
            return $paidNow;
        }
        $factor = $params->resolve($g['factor']);
        if ($factor === null || $factor['value'] === null) {
            return $paidNow;
        }
        $eps = ($variables['sistema_salud'] ?? '') === 'eps';
        $bonus = $params->resolve($eps ? $g['bonificacion_eps_pct'] : $g['bonificacion_pct']) ?? $params->resolve($g['bonificacion_pct']);
        $bonusPct = $bonus === null || $bonus['value'] === null ? '0' : (string) $bonus['value'];
        $hire = (string) ($variables['fecha_ingreso'] ?? '');
        $total = $paidNow;
        foreach ($g['pagos'] as $pay) {
            if ($pay['mes_pago'] < $month || ($pay['mes_pago'] === $month && $paidNow->isPositive())) {
                continue;
            }
            $months = self::completeMonths($hire, $year, $pay['desde_mes'], $pay['hasta_mes']);
            if ($months <= 0) {
                continue;
            }
            $grat = $ordinary->multipliedBy((string) $factor['value'])->multipliedBy($months)->dividedBy($g['meses_semestre'], 10, RoundingMode::HALF_UP);
            $grat = $grat->toScale($decimals, RoundingMode::HALF_UP);
            $total = $total->plus($grat)->plus($grat->multipliedBy($bonusPct)->dividedBy(100, $decimals, RoundingMode::HALF_UP));
        }

        return $total;
    }

    /** Meses calendario completos del semestre trabajados (se proyecta que la relación continúa). */
    private static function completeMonths(string $hire, int $year, int $fromMonth, int $toMonth): int
    {
        $start = sprintf('%04d-%02d-01', $year, $fromMonth);
        if ($hire !== '' && $hire > $start) {
            $y = (int) substr($hire, 0, 4);
            $m = (int) substr($hire, 5, 2);
            $d = (int) substr($hire, 8, 2);
            if ($y < $year) {
                return $toMonth - $fromMonth + 1;
            }
            $first = $d === 1 ? $m : $m + 1;

            return max(0, $toMonth - max($first, $fromMonth) + 1);
        }

        return $toMonth - $fromMonth + 1;
    }

    /** @return array<int|string, mixed> */
    private static function json(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<array{hasta_uit: string|null, tasa: string}> */
    private static function scale(?string $value): array
    {
        $out = [];
        foreach (self::json($value) as $row) {
            if (is_array($row) && isset($row['tasa']) && is_scalar($row['tasa'])) {
                $limit = $row['hasta_uit'] ?? null;
                $out[] = ['hasta_uit' => is_scalar($limit) ? (string) $limit : null, 'tasa' => (string) $row['tasa']];
            }
        }

        return $out;
    }

    /** @return array<int|string, string> */
    private static function numbers(?string $value): array
    {
        return array_map(fn ($v) => (string) $v, array_filter(self::json($value), fn ($v) => is_int($v) || is_string($v) || is_float($v)));
    }
}
