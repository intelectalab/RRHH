<?php

namespace App\Modules\Documents\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * DOC-6 · Flujo de firma (ME-10): Borrador → Enviado → Visto → Firmado parcialmente (in_progress) → Completado;
 * Rechazado con motivo, Vencido o Cancelado. Completar lo hace el sistema cuando firma el último firmante.
 * Abrir y recordar no cambian el estado salvo el primer «visto».
 */
final class SignatureRequestStateMachine extends StateMachine
{
    public const DRAFT = 'draft';

    public const SENT = 'sent';

    public const VIEWED = 'viewed';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const DECLINED = 'declined';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** Estados en los que la solicitud espera firmas. */
    public const OPEN = [self::SENT, self::VIEWED, self::IN_PROGRESS];

    public function transitions(): array
    {
        $open = fn (string $self): array => [
            'sign' => self::IN_PROGRESS, 'complete' => self::COMPLETED, 'decline' => self::DECLINED,
            'expire' => self::EXPIRED, 'cancel' => self::CANCELLED, 'remind' => $self,
        ];

        return [
            self::DRAFT => ['send' => self::SENT, 'cancel' => self::CANCELLED],
            self::SENT => ['view' => self::VIEWED] + $open(self::SENT),
            self::VIEWED => ['view' => self::VIEWED] + $open(self::VIEWED),
            self::IN_PROGRESS => ['view' => self::IN_PROGRESS] + $open(self::IN_PROGRESS),
            self::COMPLETED => [],
            self::DECLINED => [],
            self::EXPIRED => [],
            self::CANCELLED => [],
        ];
    }
}
