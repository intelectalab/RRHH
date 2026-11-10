<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * Empresa: activa ↔ archivada (RN-TEN-05: no se elimina, se archiva y deja de aceptar altas).
 * En base: active / inactive; en la API: active / archived.
 */
final class LegalEntityStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'active' => ['archive' => 'inactive'],
            'inactive' => ['restore' => 'active'],
        ];
    }
}
