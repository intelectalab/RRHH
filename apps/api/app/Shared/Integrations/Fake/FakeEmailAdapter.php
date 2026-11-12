<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Email\SignedFeedback;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\EmailPort;
use Throwable;

final class FakeEmailAdapter implements EmailPort
{
    /**
     * @var array<int, array{to: string, subject: string, body: string, attachments: array<string, mixed>, id: string, from: string|null}>
     */
    private array $sent = [];

    private ?Throwable $nextException = null;

    /** @var array<string, string> resultado de verificación por remitente (por defecto verified) */
    private array $senderStatus = [];

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_email';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function send(string $to, string $subject, string $body, array $attachments = [], ?string $from = null): string
    {
        $start = hrtime(true);
        $messageId = 'email_'.str()->uuid();

        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;

            $duration = (hrtime(true) - $start) / 1e6;
            $this->recorder?->record('fake_email', 'send', $duration, 'failed', $e->getMessage(), ['to' => $to]);

            throw $e;
        }

        $this->sent[] = [
            'id' => $messageId,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $attachments,
            'from' => $from,
        ];

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_email', 'send', $duration, 'success', null, ['to' => $to]);

        return $messageId;
    }

    public function verifySender(string $address): string
    {
        $this->recorder?->record('fake_email', 'verify_sender', 0.0, 'success', null, []);

        return $this->senderStatus[mb_strtolower($address)] ?? 'verified';
    }

    /** Pruebas: resultado de verificación para un remitente (verified, pending o failed). */
    public function setSenderStatus(string $address, string $status): void
    {
        $this->senderStatus[mb_strtolower($address)] = $status;
    }

    public function parseFeedback(string $payload, array $headers): array
    {
        return SignedFeedback::parse($payload, $headers);
    }

    /**
     * @return array<int, array{to: string, subject: string, body: string, attachments: array<string, mixed>, id: string, from: string|null}>
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->nextException = null;
        $this->senderStatus = [];
    }
}
