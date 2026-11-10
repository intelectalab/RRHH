<?php

namespace App\Modules\Workflow\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-43 · Solicitud de aprobación (workflow_instances).
 */
final class ApprovalRequestStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'pending' => ['assign_steps' => 'running', 'cancel' => 'cancelled'],
            'running' => ['approve_step' => 'running', 'approve_last_step' => 'approved', 'reject' => 'rejected', 'escalate' => 'escalated', 'cancel' => 'cancelled', 'delegate' => 'running'],
            'escalated' => ['approve_step' => 'running', 'approve_last_step' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled', 'delegate' => 'escalated', 'escalate' => 'escalated'],
            'approved' => [],
            'rejected' => [],
            'cancelled' => [],
        ];
    }
}
