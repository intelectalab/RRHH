<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * CPT-1 · CPT-2 · Concepto de planilla: borrador (aún sin versión publicada) → activo al publicar; publicar de
 * nuevo agrega una versión con vigencia (RN-CPT-01). Retirar lo deja fuera de los cálculos sin borrarlo
 * (RN-CPT-02). Estados en payroll_concepts.status: draft, active, inactive.
 */
final class PayrollConceptStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['publish' => 'active', 'retire' => 'inactive'],
            'active' => ['publish' => 'active', 'retire' => 'inactive'],
            'inactive' => [],
        ];
    }
}
