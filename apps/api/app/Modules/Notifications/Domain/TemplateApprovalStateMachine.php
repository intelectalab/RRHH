<?php

namespace App\Modules\Notifications\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * NOT-5 · Aprobación de una plantilla de WhatsApp por el proveedor:
 * borrador → pendiente → aprobada | rechazada; una rechazada se puede volver a enviar y cambiar el
 * texto de una aprobada (o pendiente) la devuelve a borrador. Las de otros canales no la necesitan.
 */
final class TemplateApprovalStateMachine extends StateMachine
{
    public const REQUEST = 'request_approval';

    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public const RESET = 'reset';

    public function transitions(): array
    {
        return [
            'draft' => [self::REQUEST => 'pending'],
            'pending' => [self::APPROVE => 'approved', self::REJECT => 'rejected', self::RESET => 'draft'],
            'approved' => [self::RESET => 'draft'],
            'rejected' => [self::REQUEST => 'pending', self::RESET => 'draft'],
        ];
    }
}
