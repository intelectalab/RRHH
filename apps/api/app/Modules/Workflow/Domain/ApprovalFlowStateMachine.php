<?php

namespace App\Modules\Workflow\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-42 · Definición de flujo (workflow_definitions): borrador → publicado → archivado.
 * Publicar de nuevo crea una versión; las solicitudes en curso siguen con la suya (RN-WFL-08).
 */
final class ApprovalFlowStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['publish' => 'active'],
            'active' => ['publish' => 'active', 'retire' => 'archived'],
            'archived' => ['publish' => 'active'],
        ];
    }
}
