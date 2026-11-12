<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ORG-4 · Centro de costo: activo → cerrado (closed_on = último día que admite imputaciones) → reabierto;
 * activo o cerrado → archivado → restaurado. En base: active / closed / inactive; en la API inactive = archived.
 * Un centro cerrado o archivado no admite nuevas asignaciones ni distribuciones.
 */
final class CostCenterStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'active' => ['close' => 'closed', 'archive' => 'inactive'],
            'closed' => ['reopen' => 'active', 'archive' => 'inactive'],
            'inactive' => ['restore' => 'active'],
        ];
    }
}
