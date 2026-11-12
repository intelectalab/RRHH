<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ORG-2 · Sede: activa ↔ archivada. Archivada conserva su historia y no admite nuevas asignaciones
 * (RN-ORG-05: no se archiva con relaciones laborales activas). En base: active / inactive.
 */
final class SiteStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'active' => ['archive' => 'inactive'],
            'inactive' => ['restore' => 'active'],
        ];
    }
}
