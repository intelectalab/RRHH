<?php

namespace App\Modules\CoreHR\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * HIS-2 · HIS-4 · Movimiento de personal:
 * - en aprobación → aprobar (programado o aplicado según la fecha) / rechazar / anular;
 * - programado (fecha futura) → aplicar (el día de la fecha) / editar / anular;
 * - aplicado → corregir (queda reemplazado por el movimiento de corrección; RN-HIS-06).
 * Rechazado, anulado y reemplazado son finales. Nada se borra.
 */
final class PersonnelActionStateMachine extends StateMachine
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const SCHEDULE = 'schedule';

    public const APPLY = 'apply';

    public const EDIT = 'edit';

    public const CANCEL = 'cancel';

    public const CORRECT = 'correct';

    public function transitions(): array
    {
        return [
            'draft' => [self::APPLY => 'applied', self::SCHEDULE => 'scheduled', self::CANCEL => 'cancelled'],
            'pending_approval' => [self::APPROVE => 'approved', self::REJECT => 'rejected', self::CANCEL => 'cancelled'],
            'approved' => [self::APPLY => 'applied', self::SCHEDULE => 'scheduled', self::CANCEL => 'cancelled'],
            'scheduled' => [self::APPLY => 'applied', self::EDIT => 'scheduled', self::CANCEL => 'cancelled'],
            'applied' => [self::CORRECT => 'replaced'],
            'rejected' => [],
            'cancelled' => [],
            'replaced' => [],
        ];
    }
}
