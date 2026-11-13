<?php

namespace App\Modules\CoreHR\Infrastructure\Workflow;

use App\Modules\CoreHR\Application\PersonnelActions;
use App\Shared\Workflow\ApprovalOutcomeHandler;

/**
 * HIS-2 · WFL-4 · El motor de aprobaciones aplica aquí la decisión final sobre un movimiento de personal, en
 * la misma transacción: aprobado queda programado (fecha futura) o se aplica; rechazado guarda el motivo;
 * cancelado se anula. Si no se puede aplicar (la estructura cambió), la decisión tampoco queda registrada.
 */
final class PersonnelActionOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly PersonnelActions $actions) {}

    public function subjectType(): string
    {
        return PersonnelActions::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $this->actions->approvedByFlow($subjectId);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->actions->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->actions->cancelledByFlow($subjectId, $reason);
    }
}
