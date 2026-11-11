<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use Brick\Math\RoundingMode;

/**
 * Provisión mensual de gratificación = remuneración computable ÷ 6 × gratificacion_factor
 * (dos gratificaciones al año, cada una por un semestre). Más la bonificación extraordinaria de la
 * Ley 30334 sobre esa provisión: 9 % con EsSalud y la tasa propia con EPS.
 * El régimen la desactiva con gratificacion_aplica = 0 (microempresa).
 */
final class GratificationProvisionStep implements PayrollStep
{
    /** Meses de un semestre: dato de calendario, no un valor legal. */
    private const MONTHS_PER_SEMESTER = 6;

    public function apply(CalculationState $state): void
    {
        if (! $state->flag('gratificacion_aplica')) {
            return;
        }

        $semester = $state->computableBase()->multipliedBy($state->param('gratificacion_factor')->required());
        $monthly = $semester->dividedBy(self::MONTHS_PER_SEMESTER, 10, RoundingMode::HALF_UP);
        $state->addProvision(new Line('prov_gratificacion', 'Provisión de gratificaciones', $state->money($monthly)));

        $bonusCode = $state->employee->health_scheme === 'eps'
            ? 'gratificacion_bonificacion_extraordinaria_eps_pct'
            : 'gratificacion_bonificacion_extraordinaria_pct';
        $state->addProvision(new Line('prov_bonif_extraordinaria', 'Provisión de bonificación extraordinaria', $state->money($monthly->multipliedBy($state->param($bonusCode)->asRate()))));
    }
}
