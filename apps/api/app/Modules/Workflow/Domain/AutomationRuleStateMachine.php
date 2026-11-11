<?php

namespace App\Modules\Workflow\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * WFL-5 · Regla de automatización (automation_rules): borrador → activa ⇄ pausada.
 * Retirar la manda a la papelera (soft delete) desde cualquier estado.
 */
final class AutomationRuleStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['activate' => 'active', 'retire' => 'retired'],
            'active' => ['pause' => 'paused', 'retire' => 'retired'],
            'paused' => ['activate' => 'active', 'retire' => 'retired'],
        ];
    }
}
