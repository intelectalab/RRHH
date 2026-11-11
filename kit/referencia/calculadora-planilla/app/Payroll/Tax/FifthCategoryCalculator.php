<?php

declare(strict_types=1);

namespace App\Payroll\Tax;

use App\Payroll\Parameters\ParameterValue;
use App\Payroll\PayrollException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;

/**
 * Retención mensual de renta de quinta categoría por proyección anual (Reglamento LIR art. 40).
 *
 *   proyección = ingreso del mes + remuneración mensual × meses que faltan después del actual
 *              + gratificaciones por cobrar + lo cobrado en meses anteriores (también de otros empleadores)
 *   renta neta = proyección − quinta_deduccion_uit × UIT
 *   impuesto anual = escala acumulativa quinta_escala (tramos en UIT)
 *   retención = (impuesto anual − retenciones hasta el mes de corte) ÷ divisor del mes
 *   pago extraordinario: impuesto con el pago − impuesto sin él, retenido completo en el mes
 *
 * Divisores y meses de corte vienen de quinta_divisores y quinta_cortes_retenciones: nada de la escala
 * ni del calendario de SUNAT está escrito aquí. No calcula a no domiciliados (30 % sin deducción).
 *
 * Regularización (diciembre, divisor 1) y cese (final = true): el impuesto se compara con lo retenido.
 * En el cese no se proyectan meses ni gratificaciones, se restan todas las retenciones del año y no hay
 * divisor. Si lo retenido supera el impuesto, la diferencia queda en «refund» (exceso a devolver).
 */
final class FifthCategoryCalculator
{
    private const SCALE = 10;

    /** @param Closure(string): ParameterValue $param parámetro global vigente en el periodo */
    public function __construct(private readonly Closure $param) {}

    public function calculate(
        int $month,
        BigDecimal $currentMonthIncome,
        BigDecimal $projectedMonthlyIncome,
        BigDecimal $extraordinaryIncome,
        FifthCategoryHistory $history,
        int $remainingMonths,
        bool $final = false,
    ): FifthCategoryResult {
        if ($month < 1 || $month > 12) {
            throw PayrollException::invalid("Mes de quinta fuera de rango: {$month}.");
        }

        $uit = ($this->param)('uit')->required();
        $deduction = $uit->multipliedBy(($this->param)('quinta_deduccion_uit')->required());
        $projected = $currentMonthIncome
            ->plus($projectedMonthlyIncome->multipliedBy($remainingMonths))
            ->plus($final ? BigDecimal::zero() : ($history->pendingGratifications ?? BigDecimal::zero()))
            ->plus($history->previousIncome);

        $scale = ($this->param)('quinta_escala')->json();
        $taxable = self::positive($projected->minus($deduction));
        $annualTax = $this->tax($taxable, $uit, $scale);

        // En el cese se restan todas las retenciones del año y no hay divisor.
        $cut = $final ? 12 : (int) (($this->param)('quinta_cortes_retenciones')->json()[(string) $month] ?? throw PayrollException::invalid("quinta_cortes_retenciones no tiene el mes {$month}."));
        $divisor = $final ? 1 : (int) (($this->param)('quinta_divisores')->json()[(string) $month] ?? throw PayrollException::invalid("quinta_divisores no tiene el mes {$month}."));
        $withheld = BigDecimal::zero();
        foreach ($history->withholdingsByMonth as $m => $amount) {
            if ((int) $m <= $cut) {
                $withheld = $withheld->plus($amount);
            }
        }

        $ordinary = self::positive($annualTax->minus($withheld))->dividedBy($divisor, 2, RoundingMode::HALF_UP);

        $extraordinary = BigDecimal::zero();
        if ($extraordinaryIncome->isPositive()) {
            $withExtra = $this->tax(self::positive($projected->plus($extraordinaryIncome)->minus($deduction)), $uit, $scale);
            $extraordinary = $withExtra->minus($annualTax);
        }

        // Exceso retenido: solo al regularizar (divisor 1) o en el cese, cuando ya no quedan meses para compensarlo.
        $refund = BigDecimal::zero()->toScale(2);
        if ($divisor === 1) {
            $balance = $annualTax->plus($extraordinary)->minus($withheld);
            if ($balance->isNegative()) {
                $refund = $balance->negated()->toScale(2, RoundingMode::HALF_UP);
                $extraordinary = BigDecimal::zero();
            }
        }

        return new FifthCategoryResult(
            month: $month,
            projectedAnnualIncome: $projected->toScale(2, RoundingMode::HALF_UP),
            deduction: $deduction->toScale(2, RoundingMode::HALF_UP),
            taxableIncome: $taxable->toScale(2, RoundingMode::HALF_UP),
            annualTax: $annualTax,
            previousWithholdings: $withheld->toScale(2, RoundingMode::HALF_UP),
            divisor: $divisor,
            ordinaryWithholding: $ordinary,
            extraordinaryWithholding: $extraordinary,
            withholding: $ordinary->plus($extraordinary),
            refund: $refund,
        );
    }

    /**
     * Impuesto de la escala progresiva acumulativa: cada tramo grava solo la parte que cae en él.
     *
     * @param  list<array{hasta_uit: int|float|null, tasa: int|float}>  $scale
     */
    public function tax(BigDecimal $taxable, BigDecimal $uit, array $scale): BigDecimal
    {
        $tax = BigDecimal::zero();
        $floor = BigDecimal::zero();
        foreach ($scale as $bracket) {
            $ceiling = $bracket['hasta_uit'] === null ? null : $uit->multipliedBy((string) $bracket['hasta_uit']);
            $top = $ceiling === null || $taxable->isLessThan($ceiling) ? $taxable : $ceiling;
            if ($top->isGreaterThan($floor)) {
                $tax = $tax->plus($top->minus($floor)->multipliedBy((string) $bracket['tasa'])->dividedBy(100, self::SCALE, RoundingMode::HALF_UP));
            }
            if ($ceiling === null || ! $taxable->isGreaterThan($ceiling)) {
                break;
            }
            $floor = $ceiling;
        }

        return $tax->toScale(2, RoundingMode::HALF_UP);
    }

    private static function positive(BigDecimal $v): BigDecimal
    {
        return $v->isNegative() ? BigDecimal::zero() : $v;
    }
}
