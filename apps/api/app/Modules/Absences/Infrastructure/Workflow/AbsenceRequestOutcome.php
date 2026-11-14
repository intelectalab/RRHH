<?php

namespace App\Modules\Absences\Infrastructure\Workflow;

use App\Modules\Absences\Application\AbsenceRequestService;
use App\Shared\Workflow\ApprovalOutcomeHandler;

/**
 * AUS-2 · WFL-4 · El motor de aprobaciones aplica aquí la decisión final sobre una ausencia, en la misma
 * transacción: aprobada ocupa el calendario y la asistencia (y queda en curso si ya empezó); rechazada
 * guarda el motivo; cancelada se anula.
 */
final class AbsenceRequestOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly AbsenceRequestService $absences) {}

    public function subjectType(): string
    {
        return AbsenceRequestService::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $this->absences->approvedByFlow($subjectId);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->absences->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->absences->cancelledByFlow($subjectId, $reason);
    }
}
