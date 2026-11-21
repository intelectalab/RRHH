<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-49 · Devolución de un exceso de retención de quinta (DEC-11). Nace pendiente con el monto calculado; devolverla
 * la paga al trabajador (otra persona que quien la calculó, RN-PE-QTA-18); el monto devuelto se compensa contra las
 * retenciones del periodo o, si queda saldo, se solicita a la administración tributaria; se cierra con saldo en cero
 * o con la resolución de la autoridad.
 */
final class FifthCategoryRefundStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'pending' => ['refund' => 'refunded'],
            'refunded' => ['compensate' => 'compensated', 'request_authority_refund' => 'authority_requested'],
            'compensated' => ['close' => 'closed'],
            'authority_requested' => ['close' => 'closed'],
            'closed' => [],
        ];
    }
}
