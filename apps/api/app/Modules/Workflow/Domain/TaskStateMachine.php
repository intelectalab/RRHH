<?php

namespace App\Modules\Workflow\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * WFL-8 · Tarea interna: pendiente → en curso → hecha, o cancelada.
 */
final class TaskStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'pending' => ['start' => 'in_progress', 'complete' => 'done', 'cancel' => 'cancelled'],
            'in_progress' => ['complete' => 'done', 'cancel' => 'cancelled'],
            'done' => [],
            'cancelled' => [],
        ];
    }
}
