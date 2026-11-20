<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * PER-10 · Lote de pago de la planilla: se arma en borrador, se genera el archivo (se puede regenerar mientras no
 * se confirme), se confirma con la respuesta del banco o se anula antes de confirmar.
 */
final class PaymentBatchStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'draft' => ['generate' => 'generated', 'cancel' => 'cancelled'],
            'generated' => ['generate' => 'generated', 'confirm' => 'confirmed', 'cancel' => 'cancelled'],
            'sent' => ['confirm' => 'confirmed', 'cancel' => 'cancelled'],
            'confirmed' => [],
            'rejected' => [],
            'cancelled' => [],
        ];
    }
}
