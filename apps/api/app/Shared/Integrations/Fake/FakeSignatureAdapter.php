<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\SignaturePort;
use Throwable;

final class FakeSignatureAdapter implements SignaturePort
{
    /**
     * @var array<string, array{document_id: string, signers: array<int, array{name: string, email: string, order?: int}>, status: string}>
     */
    private array $requests = [];

    private ?Throwable $nextException = null;

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_signature';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function createSignatureRequest(string $documentId, array $signers): string
    {
        $start = hrtime(true);
        $requestId = 'sig_'.str()->uuid();

        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;

            $duration = (hrtime(true) - $start) / 1e6;
            $this->recorder?->record('fake_signature', 'createSignatureRequest', $duration, 'failed', $e->getMessage());

            throw $e;
        }

        $this->requests[$requestId] = [
            'document_id' => $documentId,
            'signers' => $signers,
            'status' => 'pending',
        ];

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_signature', 'createSignatureRequest', $duration, 'success', null, ['document_id' => $documentId]);

        return $requestId;
    }

    public function checkStatus(string $signatureRequestId): string
    {
        $start = hrtime(true);

        $status = $this->requests[$signatureRequestId]['status'] ?? 'not_found';
        $duration = (hrtime(true) - $start) / 1e6;

        $this->recorder?->record('fake_signature', 'checkStatus', $duration, 'success', null, ['status' => $status]);

        return $status;
    }

    public function setStatus(string $signatureRequestId, string $status): void
    {
        if (isset($this->requests[$signatureRequestId])) {
            $this->requests[$signatureRequestId]['status'] = $status;
        }
    }

    /**
     * @return array<string, array{document_id: string, signers: array<int, array{name: string, email: string, order?: int}>, status: string}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }
}
