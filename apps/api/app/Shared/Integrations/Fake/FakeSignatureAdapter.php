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

    public function sign(string $envelopeId, array $signer, string $documentHash): array
    {
        $start = hrtime(true);
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            $this->recorder?->record('fake_signature', 'sign', (hrtime(true) - $start) / 1e6, 'failed', $e->getMessage());

            throw $e;
        }
        if (isset($this->requests[$envelopeId])) {
            $this->requests[$envelopeId]['status'] = 'signed';
        }
        $this->recorder?->record('fake_signature', 'sign', (hrtime(true) - $start) / 1e6, 'success', null, ['envelope' => $envelopeId]);

        return [
            'provider' => $this->providerName(),
            'certificate_serial' => strtoupper(substr(hash('sha256', 'cert|'.($signer['user_id'] ?? $signer['name'])), 0, 16)),
            'certificate_issuer' => 'CN=Fake CA (desarrollo)',
            'signed_at' => now()->toIso8601String(),
            'signature' => hash('sha256', $envelopeId.'|'.$documentHash.'|'.($signer['user_id'] ?? $signer['name'])),
        ];
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
