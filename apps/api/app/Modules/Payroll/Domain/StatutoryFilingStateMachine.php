<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-24 · Declaración o archivo oficial (PLAME, T-Registro, AFPnet). Nace en borrador; validar genera los archivos
 * y deja «validada» o «con errores» (se puede volver a validar); la presentación la hace la empresa en la
 * herramienta oficial (ADR 0018) y aquí se registra con su constancia; la respuesta es aceptada u observada; una
 * presentada solo se corrige con una rectificatoria, que nace como otra declaración en borrador (RN-DEC-03).
 */
final class StatutoryFilingStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['validate' => 'validated', 'validate_with_errors' => 'with_errors'],
            'with_errors' => ['validate' => 'validated', 'validate_with_errors' => 'with_errors'],
            'validated' => ['validate' => 'validated', 'validate_with_errors' => 'with_errors', 'mark_submitted' => 'submitted'],
            'generated' => ['validate' => 'validated', 'validate_with_errors' => 'with_errors'],
            'submitted' => ['accept' => 'accepted', 'observe' => 'observed'],
            'accepted' => ['rectify' => 'rectified'],
            'observed' => ['rectify' => 'rectified', 'accept' => 'accepted'],
            'rejected' => ['rectify' => 'rectified'],
            'rectified' => [],
        ];
    }
}
