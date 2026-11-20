<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * SAS-12 · RN-SAS-09 · Restauración de una copia: pedida → aprobada por un segundo usuario de la consola →
 * en curso → completada (verificada) o fallida. Mientras espera la aprobación se puede cancelar.
 */
final class BackupRestoreStateMachine extends StateMachine
{
    public const APPROVE = 'approve';

    public const CANCEL = 'cancel';

    public const RUN = 'run';

    public const COMPLETE = 'complete';

    public const FAIL = 'fail';

    public function transitions(): array
    {
        return [
            'requested' => [self::APPROVE => 'approved', self::CANCEL => 'cancelled'],
            'approved' => [self::RUN => 'running'],
            'running' => [self::COMPLETE => 'completed', self::FAIL => 'failed'],
        ];
    }
}
