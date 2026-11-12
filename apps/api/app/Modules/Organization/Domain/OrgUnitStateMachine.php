<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ORG-3 · Unidad organizativa: activa ↔ archivada. Archivar exige que no tenga posiciones activas
 * ni subunidades (RN-ORG-01); restaurar la devuelve al árbol con su última estructura.
 */
final class OrgUnitStateMachine extends StateMachine
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
