<?php

namespace App\Modules\Vacations\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * VAC-1 · Política de vacaciones: activa ↔ archivada. Una política archivada deja de aplicarse desde el
 * día siguiente; su historia de reglas se conserva y los periodos ya calculados no cambian.
 */
final class VacationPolicyStateMachine extends StateMachine
{
    public const ARCHIVE = 'archive';

    public const RESTORE = 'restore';

    public function transitions(): array
    {
        return [
            'active' => [self::ARCHIVE => 'archived'],
            'archived' => [self::RESTORE => 'active'],
        ];
    }
}
