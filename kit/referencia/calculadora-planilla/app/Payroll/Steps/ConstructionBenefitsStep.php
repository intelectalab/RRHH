<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use App\Payroll\PayrollException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Construcción civil: provisiones del mes sobre los jornales básicos.
 *
 *   CTS (compensación)  = jornales básicos × cc_compensacion_pct ÷ 100
 *   vacaciones          = jornales básicos × cc_vacaciones_pct ÷ 100
 *   gratificación       = jornal básico × cc_gratificacion_jornales ÷ fracciones del periodo
 *                         (cc_gratificacion_fracciones: sétimos de enero a julio y quintos de agosto a diciembre),
 *                         más la bonificación extraordinaria de la Ley 30334 [validar]
 *
 * En construcción civil la CTS y las vacaciones suelen pagarse al terminar la obra o la semana; aquí se
 * provisionan para el costo del empleador y el pago lo decide la planilla [validar con el especialista].
 */
final class ConstructionBenefitsStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $basic = $state->earnings['rem_basica']->amount;
        $state->addProvision(new Line('prov_cc_cts', 'Provisión de compensación por tiempo de servicios', $state->money($basic->multipliedBy($state->param('cc_compensacion_pct')->asRate()))));
        $state->addProvision(new Line('prov_cc_vacaciones', 'Provisión de compensación vacacional', $state->money($basic->multipliedBy($state->param('cc_vacaciones_pct')->asRate()))));

        $month = (int) $state->context->periodDate->format('n');
        $fractions = $state->param('cc_gratificacion_fracciones')->json();
        ksort($fractions, SORT_NUMERIC);
        $fraction = null;
        foreach ($fractions as $untilMonth => $parts) {
            if ($month <= (int) $untilMonth) {
                $fraction = (int) $parts;
                break;
            }
        }
        if (! $fraction) {
            throw PayrollException::invalid("cc_gratificacion_fracciones no cubre el mes {$month}.");
        }
        $grat = $state->dailyWage->multipliedBy($state->param('cc_gratificacion_jornales')->required())->dividedBy($fraction, 10, RoundingMode::HALF_UP);
        $state->addProvision(new Line('prov_gratificacion', 'Provisión de gratificaciones', $state->money($grat)));
        $state->addProvision(new Line('prov_bonif_extraordinaria', 'Provisión de bonificación extraordinaria', $state->money($grat->multipliedBy($state->param('gratificacion_bonificacion_extraordinaria_pct')->asRate()))));
    }
}
