<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use Brick\Math\BigDecimal;

/**
 * Asignación familiar = RMV vigente × asignacion_familiar_pct_rmv.
 * Corresponde si la ficha del colaborador lo marca (has_family_allowance: hijos que dan derecho) y si el
 * régimen la otorga (asignacion_familiar_aplica: 1 en general; 0 en micro y pequeña empresa, por confirmar).
 * Se paga completa en el mes de ingreso o cese, sin prorratear [validar con el especialista].
 */
final class FamilyAllowanceStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $amount = self::amount($state);
        if ($amount !== null) {
            $state->addEarning(new Line('asig_familiar', 'Asignación familiar', $amount));
        }
    }

    /** Monto que corresponde, o null si no corresponde. Lo usa también la liquidación. */
    public static function amount(CalculationState $state): ?BigDecimal
    {
        if (! $state->employee->has_family_allowance || ! $state->flag('asignacion_familiar_aplica')) {
            return null;
        }

        return $state->money($state->param('rmv')->required()->multipliedBy($state->param('asignacion_familiar_pct_rmv')->asRate()));
    }
}
