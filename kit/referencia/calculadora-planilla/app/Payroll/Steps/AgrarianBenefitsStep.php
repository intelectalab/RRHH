<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;

/**
 * Régimen agrario (Ley 31110): gratificación y CTS.
 *
 * - Si el colaborador eligió recibirlas prorrateadas (agrarian_prorated_benefits), se pagan cada mes:
 *     gratificación prorrateada = remuneración básica × agr_gratificacion_pct ÷ 100 (paga quinta, sin aportes)
 *     CTS prorrateada           = remuneración básica × agr_cts_pct ÷ 100 (sin aportes ni quinta)
 * - Si no, se provisionan como en el régimen general (GratificationProvisionStep y CtsProvisionStep).
 */
final class AgrarianBenefitsStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        if (! $state->employee->agrarian_prorated_benefits) {
            (new GratificationProvisionStep)->apply($state);
            (new CtsProvisionStep)->apply($state);

            return;
        }
        $basic = $state->earnings['rem_basica']->amount;
        $state->addEarning(new Line('agr_gratif_pror', 'Gratificación prorrateada', $state->money($basic->multipliedBy($state->param('agr_gratificacion_pct')->asRate())), remunerative: false, computable: false, fifthCategory: true));
        $state->addEarning(new Line('agr_cts_pror', 'CTS prorrateada', $state->money($basic->multipliedBy($state->param('agr_cts_pct')->asRate())), remunerative: false, computable: false, fifthCategory: false));
    }
}
