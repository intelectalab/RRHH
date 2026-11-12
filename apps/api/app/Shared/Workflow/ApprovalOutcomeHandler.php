<?php

namespace App\Shared\Workflow;

/**
 * WFL-4 · Lo implementa el módulo dueño de un tipo de trámite (vacaciones, gastos, posiciones…) para
 * que la decisión final se aplique en su registro en la misma transacción que la aprobación: aprobar
 * desde el centro de aprobaciones actualiza de inmediato el módulo de origen. Si el módulo rechaza
 * aplicar el resultado (lanza una excepción), la decisión tampoco queda registrada.
 *
 * Cada método debe ser idempotente: el módulo pudo haber cancelado él mismo el trámite.
 */
interface ApprovalOutcomeHandler
{
    /** Tipo de trámite que atiende (approval_requests.subject_type), p. ej. vacation_request. */
    public function subjectType(): string;

    public function approved(string $subjectId, string $approvalRequestId): void;

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void;

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void;
}
