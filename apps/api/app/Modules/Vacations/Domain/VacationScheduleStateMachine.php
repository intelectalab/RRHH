<?php

namespace App\Modules\Vacations\Domain;

use App\Shared\StateMachine\StateMachine;

/** VAC-5 · Campaña del rol anual: abierta (se proponen y confirman fechas) ⇄ cerrada. */
final class VacationScheduleStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'open' => ['close' => 'closed'],
            'closed' => ['reopen' => 'open'],
        ];
    }
}
