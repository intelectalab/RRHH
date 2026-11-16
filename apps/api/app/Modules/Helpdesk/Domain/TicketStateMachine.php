<?php

namespace App\Modules\Helpdesk\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ESS-5 · ME-31 · Solicitud a RRHH: Nuevo → tomar → En atención; En atención → pedir información → Esperando
 * información; el solicitante responde → En atención; Esperando → cerrar (inactividad); Nuevo o En atención →
 * resolver → Resuelto; Resuelto → reabrir → En atención; Resuelto → cerrar → Cerrado.
 */
final class TicketStateMachine extends StateMachine
{
    public const NEW = 'new';

    public const IN_PROGRESS = 'in_progress';

    public const WAITING_INFO = 'waiting_info';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    /** Acciones que se piden por la API (reply ocurre al responder con un mensaje). */
    public const API_ACTIONS = ['assign', 'request_info', 'resolve', 'close', 'reopen'];

    public function transitions(): array
    {
        return [
            self::NEW => ['assign' => self::IN_PROGRESS, 'request_info' => self::WAITING_INFO, 'resolve' => self::RESOLVED],
            self::IN_PROGRESS => ['assign' => self::IN_PROGRESS, 'request_info' => self::WAITING_INFO, 'resolve' => self::RESOLVED],
            self::WAITING_INFO => ['reply' => self::IN_PROGRESS, 'close' => self::CLOSED],
            self::RESOLVED => ['reopen' => self::IN_PROGRESS, 'close' => self::CLOSED],
        ];
    }

    public function isOpen(string $status): bool
    {
        return in_array($status, [self::NEW, self::IN_PROGRESS, self::WAITING_INFO], true);
    }
}
