<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * SAS-11 · Comprobante del SaaS a una organización:
 *  - borrador → emitido (el proveedor OSE o PSE lo acepta, sistema) · observado (lo rechaza, sistema) · anulado;
 *  - observado → emitido o sigue observado al reenviarlo corregido · anulado;
 *  - emitido → vencido (pasó la fecha de pago, sistema) · pagado (los pagos cubren el total, sistema);
 *  - vencido → pagado.
 * «Emitir» es la acción de la consola que lo envía al proveedor; el resultado lo ponen accept/reject. Un comprobante
 * aceptado no se anula aquí: corregirlo exige una nota de crédito (pendiente con el proveedor elegido).
 */
final class SaasInvoiceStateMachine extends StateMachine
{
    public const ISSUE = 'issue';

    public const ACCEPT = 'accept';     // sistema: constancia de aceptación

    public const REJECT = 'reject';     // sistema: rechazo de la administración tributaria

    public const EXPIRE = 'expire';     // sistema: venció sin pagarse

    public const SETTLE = 'settle';     // sistema: los pagos cubren el total

    public const VOID = 'void';

    public function transitions(): array
    {
        return [
            'draft' => [self::ISSUE => 'draft', self::ACCEPT => 'issued', self::REJECT => 'observed', self::VOID => 'void'],
            'observed' => [self::ISSUE => 'observed', self::ACCEPT => 'issued', self::REJECT => 'observed', self::VOID => 'void'],
            'issued' => [self::EXPIRE => 'overdue', self::SETTLE => 'paid'],
            'overdue' => [self::SETTLE => 'paid'],
        ];
    }
}
