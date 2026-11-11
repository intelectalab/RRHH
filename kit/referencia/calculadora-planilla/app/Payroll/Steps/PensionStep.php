<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\Parameters\MissingParameterException;
use Brick\Math\RoundingMode;

/**
 * Aporte previsional del trabajador sobre los ingresos remunerativos.
 * - ONP: base × onp_aporte_pct.
 * - AFP: base × aporte + base × comisión (flujo o mixta) + mín(base, remuneración máxima asegurable) × prima.
 *   Las tasas vienen de pension_fund_rates de la administradora, vigentes en el periodo.
 */
final class PensionStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $base = $state->remunerativeBase();
        $employee = $state->employee;

        if ($employee->pension_system === 'onp') {
            $state->addDeduction(new Line('onp_aporte', 'Aporte ONP', $state->money($base->multipliedBy($state->param('onp_aporte_pct')->asRate()))));

            return;
        }

        if ($employee->pension_system !== 'afp') {
            return;
        }

        $rates = $state->pensionFundRates((string) $employee->pension_fund_code);
        $commissionRate = match ($employee->commission_scheme) {
            'flow' => $rates->commissionFlowRate,
            'mixed' => $rates->commissionMixedRate,
            default => null,
        } ?? throw new MissingParameterException("Falta el tipo de comisión AFP de {$employee->full_name} o la tasa de esa comisión.");

        $insurableBase = $base->isGreaterThan($rates->maxInsurableRemuneration) ? $rates->maxInsurableRemuneration : $base;

        $state->addDeduction(new Line('afp_aporte', 'Aporte obligatorio AFP', $state->money($base->multipliedBy($rates->contributionRate)->dividedBy(100, 10, RoundingMode::HALF_UP))));
        $state->addDeduction(new Line('afp_comision', 'Comisión AFP', $state->money($base->multipliedBy($commissionRate)->dividedBy(100, 10, RoundingMode::HALF_UP))));
        $state->addDeduction(new Line('afp_prima', 'Prima de seguro AFP', $state->money($insurableBase->multipliedBy($rates->insurancePremiumRate)->dividedBy(100, 10, RoundingMode::HALF_UP))));
    }
}
