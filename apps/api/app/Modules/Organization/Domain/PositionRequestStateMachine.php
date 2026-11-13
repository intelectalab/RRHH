<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-04 · POS-3 · Solicitud de posición: borrador → en aprobación → aprobada / rechazada; se cancela en
 * borrador o en aprobación. En base: draft, submitted, approved, rejected, cancelled, fulfilled.
 *
 * `approve` y `reject` los aplica el motor de aprobaciones (ApprovalOutcomeHandler) cuando el flujo
 * termina: no tienen ruta propia ni aparecen en allowed_actions. `fulfill` lo marca el sistema cuando
 * todas sus posiciones quedan ocupadas.
 */
final class PositionRequestStateMachine extends StateMachine
{
    /** Acciones que solo ejecuta el sistema (el motor de aprobaciones o la ocupación). */
    public const SYSTEM_ACTIONS = ['approve', 'reject', 'fulfill'];

    public function transitions(): array
    {
        return [
            'draft' => ['submit' => 'submitted', 'cancel' => 'cancelled'],
            'submitted' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
            'approved' => ['fulfill' => 'fulfilled'],
            'rejected' => [],
            'cancelled' => [],
            'fulfilled' => [],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function manualActions(string $state): array
    {
        return array_values(array_diff($this->allowedActions($state), self::SYSTEM_ACTIONS));
    }
}
