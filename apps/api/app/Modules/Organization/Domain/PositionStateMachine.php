<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-03 · POS-2 · Posición: propuesta → aprobada → vacante / en reclutamiento / ocupada → congelada →
 * cerrada. En base: proposed, approved, open (vacante), recruiting, filled, frozen, closed.
 *
 * `fill` y `vacate` son del sistema: ocupada o vacante se deriva de las asignaciones vigentes y no se
 * edita a mano (no aparecen en allowed_actions ni tienen ruta). Cerrar equivale a eliminar la plaza:
 * una posición ocupada no se cierra (RN-POS-06); primero debe quedar vacante.
 */
final class PositionStateMachine extends StateMachine
{
    /** Acciones que solo ejecuta el sistema al sincronizar la ocupación. */
    public const SYSTEM_ACTIONS = ['fill', 'vacate'];

    public function transitions(): array
    {
        return [
            'proposed' => ['approve' => 'approved', 'reject' => 'closed'],
            'approved' => ['open-recruitment' => 'recruiting', 'fill' => 'filled', 'freeze' => 'frozen', 'close' => 'closed'],
            'open' => ['open-recruitment' => 'recruiting', 'fill' => 'filled', 'freeze' => 'frozen', 'close' => 'closed'],
            'recruiting' => ['fill' => 'filled', 'cancel-recruitment' => 'open'],
            'filled' => ['vacate' => 'open'],
            'frozen' => ['unfreeze' => 'open', 'close' => 'closed'],
            'closed' => [],
        ];
    }

    /**
     * Acciones que una persona puede pedir desde el estado actual (sin las del sistema).
     *
     * @return array<int, string>
     */
    public function manualActions(string $state): array
    {
        return array_values(array_diff($this->allowedActions($state), self::SYSTEM_ACTIONS));
    }
}
