<?php

namespace App\Modules\Vacations\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * VAC-6 · Acuerdo de vacaciones (fraccionamiento, adelanto o venta): borrador → emitido (PDF en el legajo; se puede
 * volver a emitir) → vigente al registrar el ejemplar firmado. Se anula antes de firmarse.
 */
final class VacationAgreementStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['issue' => 'issued', 'cancel' => 'cancelled'],
            'issued' => ['issue' => 'issued', 'register-signature' => 'active', 'cancel' => 'cancelled'],
            'active' => [],
            'cancelled' => [],
        ];
    }
}
