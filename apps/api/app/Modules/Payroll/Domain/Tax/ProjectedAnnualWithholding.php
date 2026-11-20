<?php

namespace App\Modules\Payroll\Domain\Tax;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * PER-5 · Retención mensual de un impuesto anual proyectado (en Perú, renta de quinta categoría; kit/docs/18
 * §2.3 y §2.4). Función pura: no lee el reloj ni la base, y no conoce ninguna cifra legal. La deducción, la
 * escala, los divisores, los cortes de retenciones y la tasa de no domiciliados llegan como parámetros con
 * vigencia (regla 4): mismas entradas ⇒ mismo resultado.
 *
 *   RBA = R·n + G + A + O ·  RN = max(0, RBA − d·U) ·  IA = T(RN)
 *   ord = (IA − C − P) / v(m) ·  adic = T(RN + X) − IA si X > 0
 *   ret = max(0, ord) + adic (m < 12) ·  ret = ord + adic en el último mes (regulariza; puede ser negativo)
 *
 * Al cese: ret = T(max(0, A + afecto del mes + O − d·U)) − C − retenciones de 1..m−1 (negativo = devolución).
 */
final class ProjectedAnnualWithholding
{
    public const VERSION = 'quinta-1';

    private const SCALE = 10;

    /**
     * @param  array{month: int, termination?: bool, resident?: bool, exempt?: bool, this_employer_withholds?: bool,
     *     ordinary_month: string, pending_gratifications: string, prior_income: string, external_received: string,
     *     external_projected: string, extraordinary_month: string, taxable_month_total: string,
     *     withholdings_by_month: array<int|string, string>, credits?: string}  $in
     * @param  array{uit: string, deduction_uit: string, scale: list<array{hasta_uit: int|float|string|null, tasa: int|float|string}>,
     *     divisors: array<int|string, int|string>, cutoffs: array<int|string, int|string>, non_resident_rate_pct?: string|null, decimals?: int}  $p
     * @return array<string, mixed>
     */
    public static function calculate(array $in, array $p): array
    {
        $dec = (int) ($p['decimals'] ?? 2);
        $m = (int) $in['month'];
        $uit = BigDecimal::of($p['uit']);
        $deduction = BigDecimal::of($p['deduction_uit'])->multipliedBy($uit);
        $credits = BigDecimal::of($in['credits'] ?? '0');
        $base = ['month' => $m, 'uit' => (string) $uit, 'deduction' => self::r($deduction, $dec), 'engine_version' => self::VERSION];

        if (($in['exempt'] ?? false) === true) {
            return $base + ['kind' => 'monthly', 'withholding' => self::r(BigDecimal::zero(), $dec), 'reason' => 'exempt'];
        }
        if (($in['this_employer_withholds'] ?? true) === false) {
            return $base + ['kind' => 'monthly', 'withholding' => self::r(BigDecimal::zero(), $dec), 'reason' => 'other_employer_withholds'];
        }
        if (($in['resident'] ?? true) === false) {
            $rate = $p['non_resident_rate_pct'] ?? null;
            if ($rate === null) {
                return $base + ['kind' => 'monthly', 'withholding' => null, 'reason' => 'non_resident', 'missing' => 'non_resident_rate_pct'];
            }
            $ret = BigDecimal::of($in['taxable_month_total'])->multipliedBy($rate)->dividedBy(100, self::SCALE, RoundingMode::HALF_UP);

            return $base + ['kind' => 'monthly', 'withholding' => self::r($ret, $dec), 'reason' => 'non_resident', 'rate_pct' => (string) $rate];
        }

        $withheld = [];
        foreach ($in['withholdings_by_month'] as $k => $v) {
            $withheld[(int) $k] = BigDecimal::of($v);
        }

        if (($in['termination'] ?? false) === true) {
            $rba = BigDecimal::of($in['prior_income'])->plus($in['taxable_month_total'])->plus($in['external_received']);
            $rn = self::max0($rba->minus($deduction));
            $ia = self::tax($rn, $uit, $p['scale'], $dec);
            $prev = BigDecimal::zero();
            foreach ($withheld as $k => $v) {
                if ($k < $m) {
                    $prev = $prev->plus($v);
                }
            }
            $ret = $ia->minus($credits)->minus($prev);

            return $base + ['kind' => 'final_adjustment', 'gross_annual_income' => self::r($rba, $dec), 'net_annual_income' => self::r($rn, $dec),
                'annual_tax' => self::r($ia, $dec), 'credits' => self::r($credits, $dec), 'prior_withholdings' => self::r($prev, $dec),
                'withholding' => self::r($ret, $dec), 'reason' => 'termination'];
        }

        $remaining = 13 - $m;
        $rba = BigDecimal::of($in['ordinary_month'])->multipliedBy($remaining)->plus($in['pending_gratifications'])
            ->plus($in['prior_income'])->plus($in['external_received'])->plus($in['external_projected']);
        $rn = self::max0($rba->minus($deduction));
        $ia = self::tax($rn, $uit, $p['scale'], $dec);
        $cutoff = (int) ($p['cutoffs'][$m] ?? $p['cutoffs'][(string) $m] ?? 0);
        $divisor = (int) ($p['divisors'][$m] ?? $p['divisors'][(string) $m] ?? 1);
        $prev = BigDecimal::zero();
        foreach ($withheld as $k => $v) {
            if ($k <= $cutoff) {
                $prev = $prev->plus($v);
            }
        }
        $ord = $ia->minus($credits)->minus($prev)->dividedBy(max(1, $divisor), $dec, RoundingMode::HALF_UP);
        $extra = BigDecimal::of($in['extraordinary_month']);
        $adic = $extra->isPositive() ? self::tax($rn->plus($extra), $uit, $p['scale'], $dec)->minus($ia) : BigDecimal::zero();
        $last = $m >= 12;
        $ret = ($last ? $ord : self::max0($ord))->plus($adic);

        return $base + [
            'kind' => 'monthly',
            'ordinary_remuneration' => self::r(BigDecimal::of($in['ordinary_month']), $dec), 'remaining_months' => $remaining,
            'pending_gratifications' => self::r(BigDecimal::of($in['pending_gratifications']), $dec), 'prior_income' => self::r(BigDecimal::of($in['prior_income']), $dec),
            'external_income' => self::r(BigDecimal::of($in['external_received'])->plus($in['external_projected']), $dec),
            'extraordinary_income' => self::r($extra, $dec),
            'gross_annual_income' => self::r($rba, $dec), 'net_annual_income' => self::r($rn, $dec), 'annual_tax' => self::r($ia, $dec),
            'credits' => self::r($credits, $dec), 'cutoff_month' => $cutoff, 'prior_withholdings' => self::r($prev, $dec), 'divisor' => $divisor,
            'ordinary_withholding' => self::r($ord, $dec), 'additional_withholding' => self::r($adic, $dec),
            'withholding' => self::r($ret, $dec), 'reason' => $last ? 'regularization' : 'projection',
        ];
    }

    /**
     * Impuesto de la escala progresiva acumulativa: Σ tasa_k · (min(x, tope_k·U) − tope_{k−1}·U).
     *
     * @param  list<array{hasta_uit: int|float|string|null, tasa: int|float|string}>  $scale
     */
    public static function tax(BigDecimal $x, BigDecimal $uit, array $scale, int $decimals = 2): BigDecimal
    {
        if (! $x->isPositive()) {
            return BigDecimal::zero()->toScale($decimals);
        }
        $total = BigDecimal::zero();
        $previous = BigDecimal::zero();
        foreach ($scale as $band) {
            $limit = $band['hasta_uit'] === null ? null : BigDecimal::of((string) $band['hasta_uit'])->multipliedBy($uit);
            $top = $limit === null || $x->isLessThan($limit) ? $x : $limit;
            $total = $total->plus($top->minus($previous)->multipliedBy((string) $band['tasa'])->dividedBy(100, self::SCALE, RoundingMode::HALF_UP));
            if ($limit === null || ! $x->isGreaterThan($limit)) {
                break;
            }
            $previous = $limit;
        }

        return $total->toScale($decimals, RoundingMode::HALF_UP);
    }

    private static function max0(BigDecimal $v): BigDecimal
    {
        return $v->isNegative() ? BigDecimal::zero() : $v;
    }

    private static function r(BigDecimal $v, int $decimals): string
    {
        return (string) $v->toScale($decimals, RoundingMode::HALF_UP);
    }
}
