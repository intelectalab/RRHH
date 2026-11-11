<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\MessagingPort;
use App\Shared\Messaging\InboundMessage;
use App\Shared\Messaging\OutboundMessage;
use App\Shared\Messaging\TemplateDecision;
use Throwable;

/**
 * Proveedor falso de SMS/WhatsApp para desarrollo y pruebas: guarda los mensajes en memoria, aprueba
 * las plantillas al instante y respeta la referencia de idempotencia (no repite un mismo envío).
 */
class FakeMessagingAdapter implements MessagingPort
{
    /**
     * @var array<int, array{phone: string, message: string, id: string, channel: string, reference: string, template: string|null}>
     */
    private array $messages = [];

    /** @var array<string, string> referencia → id del mensaje */
    private array $byReference = [];

    private ?Throwable $nextException = null;

    private ?string $rejectReason = null;

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_messaging';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function rejectNextTemplate(string $reason): void
    {
        $this->rejectReason = $reason;
    }

    public function send(OutboundMessage $message): string
    {
        $start = hrtime(true);
        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;
            $this->recorder?->record($this->providerName(), 'send', (hrtime(true) - $start) / 1e6, 'failed', $e->getMessage(), ['channel' => $message->channel]);

            throw $e;
        }
        if (isset($this->byReference[$message->reference])) {
            return $this->byReference[$message->reference];
        }

        $id = 'msg_'.str()->uuid();
        $this->byReference[$message->reference] = $id;
        $this->messages[] = [
            'id' => $id,
            'phone' => $message->to,
            'message' => $message->body,
            'channel' => $message->channel,
            'reference' => $message->reference,
            'template' => $message->providerTemplateRef,
        ];
        $this->recorder?->record($this->providerName(), 'send', (hrtime(true) - $start) / 1e6, 'success', null, ['channel' => $message->channel]);

        return $id;
    }

    public function submitTemplate(string $code, string $locale, string $body): TemplateDecision
    {
        if ($this->rejectReason !== null) {
            $reason = $this->rejectReason;
            $this->rejectReason = null;

            return new TemplateDecision('rejected', null, $reason);
        }

        return new TemplateDecision('approved', 'fake_tpl_'.str_replace('.', '_', $code).'_'.$locale);
    }

    public function parseInbound(array $payload): ?InboundMessage
    {
        $from = $payload['from'] ?? null;
        $body = $payload['body'] ?? null;
        if (! is_string($from) || ! is_string($body)) {
            return null;
        }
        $channel = ($payload['channel'] ?? 'whatsapp') === 'sms' ? 'sms' : 'whatsapp';
        $id = $payload['id'] ?? null;

        return new InboundMessage($channel, $from, $body, is_string($id) ? $id : 'in_'.str()->uuid());
    }

    /**
     * @return array<int, array{phone: string, message: string, id: string, channel: string, reference: string, template: string|null}>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
