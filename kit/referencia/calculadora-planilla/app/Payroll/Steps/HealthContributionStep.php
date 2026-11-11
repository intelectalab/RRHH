<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use Brick\Math\BigDecimal;

/**
 * Aporte de salud a cargo del empleador.
 * - EsSalud o EPS: base remunerativa × essalud_aporte_pct (el régimen puede tener su propia tasa, como el
 *   agrario). Con EPS, el crédito eps_credito_pct se descuenta del aporte.
 * - SIS (microempresa): monto fijo mype_sis_aporte por trabajador.
 */
final class HealthContributionStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        if ($state->employee->health_scheme === 'sis') {
            $state->addEmployerContribution(new Line('sis_mype', 'Aporte al SIS', $state->money($state->param('mype_sis_aporte')->required())));

            return;
        }

        $rate = $state->param('essalud_aporte_pct')->asRate();
        if ($state->employee->health_scheme === 'eps') {
            $rate = $rate->multipliedBy(BigDecimal::one()->minus($state->param('eps_credito_pct')->asRate()));
        }

        $state->addEmployerContribution(new Line('essalud', 'Aporte EsSalud', $state->money($state->remunerativeBase()->multipliedBy($rate))));
    }
}
