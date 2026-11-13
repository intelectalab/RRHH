<?php

namespace App\Modules\Organization\Infrastructure\Workflow;

use App\Modules\Organization\Application\PositionRequestService;
use App\Shared\Workflow\ApprovalOutcomeHandler;

/**
 * POS-3 · WFL-4 · El motor de aprobaciones aplica aquí la decisión final sobre una solicitud de posición,
 * en la misma transacción: aprobada crea sus posiciones; rechazada guarda el motivo; cancelada se anula.
 * Si no se puede aplicar (la estructura ya no admite la plaza), la decisión tampoco queda registrada.
 */
final class PositionRequestOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly PositionRequestService $requests) {}

    public function subjectType(): string
    {
        return PositionRequestService::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $this->requests->approvedByFlow($subjectId);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->requests->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->requests->cancelledByFlow($subjectId, $reason);
    }
}
