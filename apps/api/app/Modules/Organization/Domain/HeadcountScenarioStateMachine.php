<?php

namespace App\Modules\Organization\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * POS-5 · Escenario de planificación: borrador → en revisión → aprobado → aplicado. Se devuelve a borrador desde
 * revisión o aprobado y se archiva en cualquier momento. Solo el borrador se edita; aplicar es la única acción que
 * toca posiciones reales (RN-POS-08).
 */
final class HeadcountScenarioStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['submit' => 'in_review', 'archive' => 'archived'],
            'in_review' => ['approve' => 'approved', 'return' => 'draft', 'archive' => 'archived'],
            'approved' => ['apply' => 'applied', 'return' => 'draft', 'archive' => 'archived'],
            'applied' => ['archive' => 'archived'],
            'archived' => [],
        ];
    }
}
