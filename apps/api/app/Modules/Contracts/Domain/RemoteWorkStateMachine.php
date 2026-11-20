<?php

namespace App\Modules\Contracts\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * CON-6 · Teletrabajo: solicitada → aprobada (o rechazada) → en firma → vigente → terminada. La emisión exige el
 * contenido mínimo del acuerdo (RN-CON-10); la firma se registra como la del contrato (ejemplar escaneado o firma
 * electrónica cuando exista, D-10).
 */
final class RemoteWorkStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'requested' => ['approve' => 'approved', 'reject' => 'rejected'],
            'approved' => ['issue' => 'pending_signature'],
            'pending_signature' => ['register-signature' => 'active', 'issue' => 'pending_signature'],
            'active' => ['end' => 'ended'],
            'rejected' => [],
            'ended' => [],
        ];
    }
}
