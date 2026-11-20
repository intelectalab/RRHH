<?php

namespace App\Modules\Vacations\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * VAC-5 · Línea del rol anual: propuesta → confirmada o declinada por el trabajador → convertida en solicitud
 * aprobada. Una propuesta o una confirmada se puede retirar antes de convertirse.
 */
final class VacationScheduleLineStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'proposed' => ['confirm' => 'confirmed', 'decline' => 'declined', 'withdraw' => 'withdrawn'],
            'confirmed' => ['convert' => 'converted', 'withdraw' => 'withdrawn'],
            'declined' => [],
            'converted' => [],
            'withdrawn' => [],
        ];
    }
}
