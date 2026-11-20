<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * BSO-3 · Distribución anual de utilidades: se calcula (y recalcula) en borrador, se aprueba y la planilla de
 * utilidades la marca pagada al cerrarse. Una aprobada no se recalcula.
 */
final class ProfitSharingStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['calculate' => 'calculated'],
            'calculated' => ['calculate' => 'calculated', 'approve' => 'approved'],
            'approved' => ['mark-paid' => 'paid'],
            'paid' => [],
        ];
    }
}
