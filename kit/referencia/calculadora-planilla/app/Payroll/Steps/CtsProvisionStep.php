<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Provisión mensual de CTS.
 *   remuneración computable de CTS = remuneración computable + gratificación del semestre × cts_fraccion_gratificacion
 *   provisión = remuneración computable de CTS ÷ 12 × (cts_dias_por_anio del régimen ÷ cts_dias_por_anio general)
 * En el régimen general la proporción es 1; en la pequeña empresa, 15 ÷ 30 = 0,5.
 * El régimen la desactiva con cts_aplica = 0 (microempresa). El tope de la pequeña empresa
 * (cts_tope_dias) se aplica al depósito acumulado, no a la provisión del mes.
 */
final class CtsProvisionStep implements PayrollStep
{
    /** Meses del año: dato de calendario, no un valor legal. */
    private const MONTHS_PER_YEAR = 12;

    public function apply(CalculationState $state): void
    {
        if (! $state->flag('cts_aplica')) {
            return;
        }

        $computable = $state->computableBase();
        $gratification = $state->flag('gratificacion_aplica')
            ? $computable->multipliedBy($state->param('gratificacion_factor')->required())
            : BigDecimal::zero();

        $ctsBase = $computable->plus($gratification->multipliedBy($state->param('cts_fraccion_gratificacion')->required()));
        $proportion = $state->param('cts_dias_por_anio')->required()
            ->dividedBy($state->globalParam('cts_dias_por_anio')->required(), 10, RoundingMode::HALF_UP);
        $state->addProvision(new Line('prov_cts', 'Provisión de CTS', $state->money($ctsBase->dividedBy(self::MONTHS_PER_YEAR, 10, RoundingMode::HALF_UP)->multipliedBy($proportion))));
    }
}
