<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\PayrollException;
use Brick\Math\BigDecimal;

/**
 * Régimen agrario (Ley 31110 art. 9): aporte a EsSalud según el tramo de la razón social.
 *
 *   aporte = base remunerativa × agr_essalud_pct[tramo] ÷ 100
 *
 * agr_essalud_pct es una tabla {"A": tasa, "B": tasa} con vigencia, porque la tasa del tramo B sube por
 * etapas. El tramo (agrarian_tier) lo define la empresa por número de trabajadores y ventas. Mientras la
 * tabla no esté confirmada, el cálculo queda con error en lugar de usar una tasa supuesta.
 */
final class AgrarianHealthContributionStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $tier = (string) ($state->employee->agrarian_tier ?? '');
        $rates = $state->param('agr_essalud_pct')->json();
        if (! array_key_exists($tier, $rates)) {
            throw PayrollException::invalid("Falta el tramo de EsSalud agrario de {$state->employee->full_name} o su tasa en agr_essalud_pct.");
        }
        $rate = BigDecimal::of((string) $rates[$tier])->dividedBy(100, 10);
        $state->addEmployerContribution(new Line('essalud', "Aporte EsSalud agrario (tramo {$tier})", $state->money($state->remunerativeBase()->multipliedBy($rate))));
    }
}
