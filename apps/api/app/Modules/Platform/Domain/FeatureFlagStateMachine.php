<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * SAS-7 · Bandera de funcionalidad: activa (se evalúa) ⇄ archivada (deja de evaluarse; sus reglas y
 * activaciones se conservan por si se restaura). Una bandera no se elimina: queda en la auditoría.
 */
final class FeatureFlagStateMachine extends StateMachine
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
