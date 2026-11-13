<?php

namespace App\Modules\CoreHR\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * HIS-3 · Cambio de compensación (personnel_actions de tipo salary_change): pendiente de aprobación →
 * aplicado / rechazado / cancelado. Sin aprobación requerida nace aplicado. «apply» y «reject» los dispara
 * el motor de aprobaciones; «cancel», quien lo pidió o quien tiene people.compensations.cancel.
 */
final class CompensationChangeStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'pending_approval' => ['apply' => 'applied', 'reject' => 'rejected', 'cancel' => 'cancelled'],
            'applied' => [],
            'rejected' => [],
            'cancelled' => [],
        ];
    }
}
