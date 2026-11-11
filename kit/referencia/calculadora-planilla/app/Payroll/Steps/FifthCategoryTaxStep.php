<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\Settlement\SettlementService;
use App\Payroll\Tax\FifthCategoryCalculator;
use Brick\Math\BigDecimal;

/**
 * Retención de renta de quinta categoría del mes (RN-NOM-13 a RN-NOM-16).
 *
 * - Si el contexto trae la retención ya calculada, se usa tal cual.
 * - Si trae FifthCategoryHistory, se proyecta con FifthCategoryCalculator:
 *     ingreso del mes     = líneas que pagan quinta y no son extraordinarias
 *     remuneración mensual = la parte regular (computable) con el sueldo completo, para los meses que faltan
 *     pago extraordinario = líneas marcadas como extraordinarias
 *   En el mes de cese se calcula como cese: sin proyectar, con todas las retenciones del año y sin divisor.
 *   Si al regularizar o al cesar lo retenido supera el impuesto, el exceso se devuelve cuando
 *   quinta_devuelve_exceso está confirmado; si no, queda un aviso.
 * - Si no trae ninguno de los dos, no se retiene y el resultado lleva un aviso.
 */
final class FifthCategoryTaxStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $context = $state->context;
        $amount = $context->fifthCategoryWithholding;

        if ($amount === null && $context->fifthCategoryHistory !== null) {
            $taxable = array_filter($state->earnings, fn (Line $l) => $l->isFifthCategoryIncome());
            $current = $state->sum(array_filter($taxable, fn (Line $l) => ! $l->extraordinary));
            $extraordinary = $state->sum(array_filter($taxable, fn (Line $l) => $l->extraordinary));
            $regular = $state->sum(array_filter($taxable, fn (Line $l) => $l->computable && ! $l->extraordinary));

            // La remuneración que se proyecta es la del mes completo, aunque este mes se haya prorrateado.
            $basic = $state->earnings['rem_basica']->amount ?? BigDecimal::zero();
            $monthly = $regular->minus($basic)->plus($state->monthlySalary ?? $basic);

            $month = (int) $context->periodDate->format('n');
            $result = (new FifthCategoryCalculator(fn (string $code) => $state->globalParam($code)))->calculate(
                month: $month,
                currentMonthIncome: $current,
                projectedMonthlyIncome: $monthly,
                extraordinaryIncome: $extraordinary,
                history: $context->fifthCategoryHistory,
                remainingMonths: $state->terminatesInPeriod() ? 0 : 12 - $month,
                final: $state->terminatesInPeriod(),
            );
            $state->fifthCategory = $result->toArray();
            $amount = $result->withholding;
            if ($result->refund?->isPositive()) {
                SettlementService::refund($state, $result->refund);
            }
        }

        if ($amount === null) {
            $state->warnings[] = 'La retención de quinta categoría no se calculó: falta el historial del año (FifthCategoryHistory).';

            return;
        }

        if ($amount->isPositive()) {
            $state->addDeduction(new Line('renta_quinta', 'Retención de renta de quinta categoría', $state->money($amount)));
        }
    }
}
