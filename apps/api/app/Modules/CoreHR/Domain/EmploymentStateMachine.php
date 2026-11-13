<?php

namespace App\Modules\CoreHR\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-01 · Relación laboral. En base: pending (Por ingresar), active, suspended, terminating
 * (En proceso de cese), terminated (Cesada), cancelled (Anulada).
 *
 * COL-2 implementa el ingreso: activar (manual o por la fecha, RN-COL-04) y anular. COL-11 implementa el
 * cese: registrarlo (terminate), revertirlo antes de la fecha (cancel-termination) y hacerlo efectivo el día
 * del cese (make-effective, solo el sistema). Suspender y reanudar son de HIS: la matriz completa se declara
 * aquí para que la prueba de matriz cubra todas las transiciones, pero solo las de IMPLEMENTED aparecen en
 * allowed_actions.
 */
final class EmploymentStateMachine extends StateMachine
{
    /** Acciones con ruta y caso de uso en esta versión. */
    public const IMPLEMENTED = ['activate', 'cancel', 'terminate', 'cancel-termination'];

    /** Estados en los que la persona tiene (o tendrá) una relación vigente con la empresa (RN-COL-02). */
    public const OPEN_STATES = ['pending', 'active', 'suspended', 'terminating'];

    public function transitions(): array
    {
        return [
            'pending' => ['activate' => 'active', 'cancel' => 'cancelled'],
            'active' => ['suspend' => 'suspended', 'terminate' => 'terminating'],
            'suspended' => ['resume' => 'active', 'terminate' => 'terminating'],
            'terminating' => ['cancel-termination' => 'active', 'make-effective' => 'terminated'],
            'terminated' => [],
            'cancelled' => [],
        ];
    }

    /**
     * Acciones que una persona puede pedir hoy desde el estado actual.
     *
     * @return array<int, string>
     */
    public function manualActions(string $state): array
    {
        return array_values(array_intersect($this->allowedActions($state), self::IMPLEMENTED));
    }
}
