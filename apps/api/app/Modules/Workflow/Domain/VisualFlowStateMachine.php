<?php

namespace App\Modules\Workflow\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * WFL-6 · Flujo del constructor visual: borrador → publicado (activo) ⇄ pausado; publicar de nuevo saca una
 * versión nueva sin cortar lo que está en curso. Retirar lo archiva (papelera) desde cualquier estado.
 * Estados en workflow_definitions: draft, active, inactive (pausado) y archived.
 */
final class VisualFlowStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['publish' => 'active', 'retire' => 'archived'],
            'active' => ['publish' => 'active', 'pause' => 'inactive', 'retire' => 'archived'],
            'inactive' => ['publish' => 'inactive', 'activate' => 'active', 'retire' => 'archived'],
        ];
    }
}
