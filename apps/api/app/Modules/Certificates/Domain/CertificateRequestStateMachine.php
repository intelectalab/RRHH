<?php

namespace App\Modules\Certificates\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ESS-4 · Solicitud de constancia: requested (espera a RRHH) → issued | rejected; con flujo de aprobación
 * publicado pasa a in_progress y la decisión del flujo la emite o la rechaza. Las de emisión automática
 * (constancias.emision_automatica) nacen y se emiten en el mismo paso.
 */
final class CertificateRequestStateMachine extends StateMachine
{
    public const REQUESTED = 'requested';

    public const IN_PROGRESS = 'in_progress';

    public const ISSUED = 'issued';

    public const REJECTED = 'rejected';

    public function transitions(): array
    {
        return [
            self::REQUESTED => ['issue' => self::ISSUED, 'reject' => self::REJECTED, 'submit' => self::IN_PROGRESS],
            self::IN_PROGRESS => ['approve' => self::ISSUED, 'decline' => self::REJECTED, 'withdraw' => self::REQUESTED],
        ];
    }

    /**
     * Acciones que RRHH puede pedir por la API (las del flujo las aplica el motor de aprobaciones).
     *
     * @return list<string>
     */
    public function hrActions(string $status): array
    {
        return array_values(array_intersect($this->allowedActions($status), ['issue', 'reject']));
    }
}
