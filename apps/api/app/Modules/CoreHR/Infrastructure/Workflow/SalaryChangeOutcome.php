<?php

namespace App\Modules\CoreHR\Infrastructure\Workflow;

use App\Modules\CoreHR\Application\CompensationService;
use App\Shared\Workflow\ApprovalOutcomeHandler;

/**
 * HIS-3 · WFL-4 · El motor de aprobaciones aplica aquí la decisión final sobre un cambio de sueldo, en la
 * misma transacción: aprobado registra la compensación con su vigencia; rechazado guarda el motivo;
 * cancelado se anula. Si no se puede registrar (otra compensación ya empieza ese día), la decisión tampoco
 * queda registrada.
 */
final class SalaryChangeOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly CompensationService $compensations) {}

    public function subjectType(): string
    {
        return CompensationService::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $this->compensations->approvedByFlow($subjectId);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->compensations->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->compensations->cancelledByFlow($subjectId, $reason);
    }
}
