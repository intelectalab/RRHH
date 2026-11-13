<?php

namespace App\Modules\Contracts\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * CON-3 · Adenda al contrato: Borrador → En firma (se genera el documento) → Vigente al registrar la
 * firma, momento en que aplica sus cambios con vigencia (RN-CON-08). Se cancela antes de firmarse.
 * En base (`contract_amendments.status`): draft, pending_signature, active, cancelled.
 * Como el contrato, una adenda física ya firmada se registra desde Borrador (RN-CON-06).
 */
final class AmendmentStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['issue' => 'pending_signature', 'register-signature' => 'active', 'cancel' => 'cancelled'],
            'pending_signature' => ['register-signature' => 'active', 'cancel' => 'cancelled'],
            'active' => [],
            'cancelled' => [],
        ];
    }
}
