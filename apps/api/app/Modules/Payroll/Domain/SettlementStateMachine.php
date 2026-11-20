<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-23 · Liquidación de beneficios sociales (LIQ-1 a LIQ-4): se calcula (y recalcula) en borrador o calculada, se envía
 * a aprobación, se aprueba (inmutable, RN-LIQ-07) o se devuelve, se paga dentro del plazo legal y se cierra con la
 * entrega de los documentos de cese firmados. Se anula solo antes de aprobarse (cese revertido).
 */
final class SettlementStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['calculate' => 'calculated', 'void' => 'voided'],
            'calculated' => ['calculate' => 'calculated', 'submit' => 'pending_approval', 'void' => 'voided'],
            'pending_approval' => ['approve' => 'approved', 'return' => 'calculated', 'void' => 'voided'],
            'approved' => ['mark-paid' => 'paid'],
            'paid' => ['deliver-documents' => 'documents_delivered'],
            'documents_delivered' => [],
            'voided' => [],
        ];
    }
}
