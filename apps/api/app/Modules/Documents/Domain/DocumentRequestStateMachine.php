<?php

namespace App\Modules\Documents\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * DOC-3 · Solicitud de documento (ME-12, lado de la solicitud): Solicitado → Entregado (en revisión) → Aprobado,
 * o Rechazado con observación, que vuelve al colaborador para entregarlo de nuevo. Se cancela mientras no esté
 * aprobada. Aprobar y rechazar los decide el motor de aprobaciones (flujo document_request).
 */
final class DocumentRequestStateMachine extends StateMachine
{
    public const REQUESTED = 'requested';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    public function transitions(): array
    {
        return [
            self::REQUESTED => ['submit' => self::SUBMITTED, 'cancel' => self::CANCELLED],
            self::SUBMITTED => ['approve' => self::APPROVED, 'reject' => self::REJECTED, 'cancel' => self::CANCELLED],
            self::REJECTED => ['submit' => self::SUBMITTED, 'cancel' => self::CANCELLED],
            self::APPROVED => [],
            self::CANCELLED => [],
        ];
    }
}
