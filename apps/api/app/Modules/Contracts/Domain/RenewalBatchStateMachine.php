<?php

namespace App\Modules\Contracts\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * CON-5 · Lote de renovación: Borrador → Enviado a firma → Completado (cuando todas las renovaciones del lote quedan
 * firmadas); se cancela mientras está en Borrador.
 */
final class RenewalBatchStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['send' => 'sent_to_signature', 'cancel' => 'cancelled'],
            'sent_to_signature' => ['complete' => 'completed'],
            'in_review' => [],
            'approved' => [],
            'completed' => [],
            'cancelled' => [],
        ];
    }
}
