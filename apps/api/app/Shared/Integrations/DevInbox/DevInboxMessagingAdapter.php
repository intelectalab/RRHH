<?php

namespace App\Shared\Integrations\DevInbox;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\MessagingPort;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Solo desarrollo: cada SMS/WhatsApp llega como correo al buzón local de pruebas (Mailpit),
 * dirigido a <número>@sms.gestiahr.test. Nunca en producción.
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

    public function sendMessage(string $phoneNumber, string $message): string
    {
        $start = hrtime(true);
        $digits = preg_replace('/\D/', '', $phoneNumber) ?? '';
        Mail::raw($message, function (Message $m) use ($digits, $phoneNumber): void {
            $m->to($digits.'@sms.gestiahr.test')->subject('SMS / WhatsApp → '.$phoneNumber);
        });
        $this->recorder->record('dev_inbox_messaging', 'sendMessage', (hrtime(true) - $start) / 1e6, 'success', null, []);

        return 'dev_'.Str::uuid();
    }
}
