<?php

namespace App\Shared\Labor;

/**
 * AUS-5 · Acuerdo de teletrabajo vigente de una relación laboral (CON-6), para que ausencias y notificaciones
 * distingan los días remotos y la desconexión sin importar el módulo de contratos.
 */
interface RemoteWorkAgreements
{
    /**
     * Acuerdo vigente (firmado y sin terminar) en la fecha.
     *
     * @return array{id: string, modality: string, starts_on: string, ends_on: string|null, disconnection_hours: int|null}|null
     */
    public function activeOn(string $employmentId, string $date): ?array;
}
