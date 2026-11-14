<?php

namespace App\Modules\Absences\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * AUS-3 · Expediente de subsidio (reembolso ante la seguridad social):
 * por presentar → presentado → observado (se vuelve a presentar) / aprobado → reembolsado, o rechazado.
 * La anulación (cancel) la hace el sistema cuando el descanso deja de tener días de subsidio antes de presentarse.
 */
final class SubsidyCaseStateMachine extends StateMachine
{
    public const FILE = 'file';

    public const OBSERVE = 'observe';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const REIMBURSE = 'reimburse';

    public const CANCEL = 'cancel';

    /** Acciones que se piden por la API (cancel es solo del sistema). */
    public const USER_ACTIONS = [self::FILE, self::OBSERVE, self::APPROVE, self::REJECT, self::REIMBURSE];

    public function transitions(): array
    {
        return [
            'pending_filing' => [self::FILE => 'filed', self::CANCEL => 'cancelled'],
            'filed' => [self::OBSERVE => 'observed', self::APPROVE => 'approved', self::REJECT => 'rejected'],
            'observed' => [self::FILE => 'filed', self::REJECT => 'rejected'],
            'approved' => [self::REIMBURSE => 'reimbursed'],
        ];
    }

    /** @return list<string> */
    public function userActions(string $state): array
    {
        return array_values(array_intersect($this->allowedActions($state), self::USER_ACTIONS));
    }
}
