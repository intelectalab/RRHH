<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\EmailPort;
use Throwable;

final class FakeEmailAdapter implements EmailPort
{
    /**
     * @var array<int, array{to: string, subject: string, body: string, attachments: array<string, mixed>, id: string}>
     */
    private array $sent = [];

    private ?Throwable $nextException = null;

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

    public function send(string $to, string $subject, string $body, array $attachments = []): string
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
        ];

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_email', 'send', $duration, 'success', null, ['to' => $to]);

        return $messageId;
    }

    /**
     * @return array<int, array{to: string, subject: string, body: string, attachments: array<string, mixed>, id: string}>
     */
    public function getSent(): array
    {
        return $this->sent;
    }

    public function clear(): void
    {
        $this->sent = [];
        $this->nextException = null;
    }
}
