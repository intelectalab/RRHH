<?php

namespace App\Shared\Time;

use Carbon\CarbonImmutable;

/**
 * AUS-5 · Desconexión digital (Ley 31572): fuera de su jornada, a un teletrabajador no se le envían avisos no
 * urgentes. Notificaciones pregunta aquí hasta cuándo diferir un aviso para un usuario, sin importar el módulo
 * de ausencias ni el de contratos.
 */
interface DisconnectionWindow
{
    /** Momento (UTC) en que termina la desconexión del usuario, o null si el aviso puede salir ya. */
    public function resumeAt(string $userId, CarbonImmutable $now): ?CarbonImmutable;
}
