<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;

/** Horas extras, bonificaciones y demás ingresos del periodo que llegan en el contexto. */
final class AdditionalEarningsStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        foreach ($state->context->earnings as $line) {
            $state->addEarning($line);
        }
    }
}
