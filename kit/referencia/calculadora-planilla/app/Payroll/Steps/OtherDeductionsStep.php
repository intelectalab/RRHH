<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;

/** Préstamos, adelantos, retenciones judiciales y demás descuentos del periodo. */
final class OtherDeductionsStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        foreach ($state->context->deductions as $line) {
            $state->addDeduction($line);
        }
    }
}
