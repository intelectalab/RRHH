<?php

namespace App\Modules\Certificates\Infrastructure\Workflow;

use App\Modules\Certificates\Application\CertificateService;
use App\Shared\Workflow\ApprovalOutcomeHandler;

/** ESS-4 · La decisión del flujo de constancias se aplica en la solicitud (aprobada = emitida). */
final class CertificateRequestOutcome implements ApprovalOutcomeHandler
{
    public function __construct(private readonly CertificateService $certificates) {}

    public function subjectType(): string
    {
        return CertificateService::SUBJECT_TYPE;
    }

    public function approved(string $subjectId, string $approvalRequestId): void
    {
        $this->certificates->approvedByFlow($subjectId);
    }

    public function rejected(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->certificates->rejectedByFlow($subjectId, $reason);
    }

    public function cancelled(string $subjectId, string $approvalRequestId, ?string $reason): void
    {
        $this->certificates->cancelledByFlow($subjectId);
    }
}
