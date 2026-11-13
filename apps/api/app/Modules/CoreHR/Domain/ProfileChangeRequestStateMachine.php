<?php

namespace App\Modules\CoreHR\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * COL-4 · RN-COL-06 · Solicitud de cambio de datos personales: pendiente → aprobada / rechazada / cancelada.
 * Una solicitud cuyos campos son todos de cambio directo nace aprobada (no espera a RRHH).
 */
final class ProfileChangeRequestStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'pending' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
            'approved' => [],
            'rejected' => [],
            'cancelled' => [],
        ];
    }
}
