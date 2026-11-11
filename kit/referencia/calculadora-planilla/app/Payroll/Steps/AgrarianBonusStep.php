<?php

declare(strict_types=1);

namespace App\Payroll\Steps;

use App\Payroll\Calculation\CalculationState;
use App\Payroll\Calculation\Line;
use Brick\Math\RoundingMode;

/**
 * Régimen agrario (Ley 31110): bonificación especial por trabajo agrario (BETA).
 *
 *   BETA = RMV × agr_beta_pct_rmv ÷ 100 × días pagados ÷ dias_base_mes
 *
 * No es remunerativa: no entra en la base de pensión, salud, gratificación ni CTS. Se trata como no
 * afecta a quinta [validar con el especialista].
 */
final class AgrarianBonusStep implements PayrollStep
{
    public function apply(CalculationState $state): void
    {
        $baseDays = $state->globalParam('dias_base_mes')->required()->toInt();
        $days = $state->workedDays ?? $baseDays;
        $amount = $state->param('rmv')->required()->multipliedBy($state->param('agr_beta_pct_rmv')->asRate())
            ->multipliedBy($days)->dividedBy($baseDays, 10, RoundingMode::HALF_UP);
        $state->addEarning(new Line('agr_beta', 'Bonificación especial por trabajo agrario (BETA)', $state->money($amount), remunerative: false, computable: false, fifthCategory: false));
    }
}
