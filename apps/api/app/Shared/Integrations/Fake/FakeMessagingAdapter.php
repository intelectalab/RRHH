<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\MessagingPort;
use Throwable;

final class FakeMessagingAdapter implements MessagingPort
{
    /**
     * @var array<int, array{phone: string, message: string, id: string}>
     */
    private array $messages = [];

    private ?Throwable $nextException = null;

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_whatsapp';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function sendMessage(string $phoneNumber, string $message): string
    {
        $start = hrtime(true);
        $messageId = 'msg_'.str()->uuid();

        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;

            $duration = (hrtime(true) - $start) / 1e6;
            $this->recorder?->record('fake_whatsapp', 'sendMessage', $duration, 'failed', $e->getMessage(), ['phone' => $phoneNumber]);

            throw $e;
        }

        $this->messages[] = [
            'id' => $messageId,
            'phone' => $phoneNumber,
            'message' => $message,
        ];

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_whatsapp', 'sendMessage', $duration, 'success', null, ['phone' => $phoneNumber]);

        return $messageId;
    }

    /**
     * @return array<int, array{phone: string, message: string, id: string}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
