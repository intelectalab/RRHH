<?php

namespace App\Modules\Documents\Infrastructure\Workflow;

use App\Modules\Documents\Application\DocumentRequestService;
use App\Shared\Workflow\ApprovalOutcomeHandler;

/**
 * DOC-3 · WFL-4 · El motor de aprobaciones aplica aquí la revisión de un documento entregado, en la misma
 * transacción: aprobado, el documento queda vigente; rechazado, el documento queda rechazado con la observación y
 * la solicitud vuelve al colaborador; cancelado, la solicitud se anula. Idempotente.
 */
final class DocumentRequestOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly DocumentRequestService $requests) {}

    public function subjectType(): string
    {
        return DocumentRequestService::SUBJECT_TYPE;
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
