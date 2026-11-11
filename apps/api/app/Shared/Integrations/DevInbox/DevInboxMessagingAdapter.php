<?php

namespace App\Shared\Integrations\DevInbox;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\MessagingPort;
use App\Shared\Messaging\InboundMessage;
use App\Shared\Messaging\OutboundMessage;
use App\Shared\Messaging\TemplateDecision;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Solo desarrollo: cada SMS/WhatsApp llega como correo al buzón local de pruebas (Mailpit),
 * dirigido a <número>@sms.gestiahr.test. Las plantillas de WhatsApp se aprueban al instante.
 * Nunca en producción.
 */
final class DevInboxMessagingAdapter implements MessagingPort
{
    public function __construct(private readonly IntegrationCallRecorder $recorder) {}

    public function providerName(): string
    {
        return 'dev_inbox_messaging';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function send(OutboundMessage $message): string
    {
        $start = hrtime(true);
        $digits = preg_replace('/\D/', '', $message->to) ?? '';
        $label = $message->channel === 'whatsapp' ? 'WhatsApp' : 'SMS';
        Mail::raw($message->body, function (Message $m) use ($digits, $message, $label): void {
            $m->to($digits.'@sms.gestiahr.test')->subject($label.' → '.$message->to);
        });
        $this->recorder->record($this->providerName(), 'send', (hrtime(true) - $start) / 1e6, 'success', null, ['channel' => $message->channel]);

        return 'dev_'.Str::uuid();
    }

    public function submitTemplate(string $code, string $locale, string $body): TemplateDecision
    {
        return new TemplateDecision('approved', 'dev_tpl_'.str_replace('.', '_', $code).'_'.$locale);
    }

    public function parseInbound(array $payload): ?InboundMessage
    {
        $from = $payload['from'] ?? null;
        $body = $payload['body'] ?? null;

        return is_string($from) && is_string($body) ? new InboundMessage('whatsapp', $from, $body, 'dev_in_'.Str::uuid()) : null;
    }
}
