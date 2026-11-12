<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ORG-5 · Puestos, familias, niveles y categorías: activo ↔ archivado. Archivado conserva su historia
 * y no admite nuevas posiciones ni nuevos puestos (RN-ORG-04: nunca se elimina). En base: active / inactive.
 */
final class JobCatalogStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'active' => ['archive' => 'inactive'],
            'inactive' => ['restore' => 'active'],
        ];
    }
}
