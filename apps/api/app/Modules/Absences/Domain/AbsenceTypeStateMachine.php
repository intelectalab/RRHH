<?php

namespace App\Modules\Absences\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * AUS-1 · Tipo de ausencia: activo → archivado. Un tipo archivado no se ofrece en solicitudes nuevas;
 * las ausencias ya registradas lo conservan.
 */
final class AbsenceTypeStateMachine extends StateMachine
{
    public const ARCHIVE = 'archive';

    public function transitions(): array
    {
        return [
            'active' => [self::ARCHIVE => 'archived'],
        ];
    }
}
