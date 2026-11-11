<?php

namespace App\Shared\Integrations\DevInbox;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\EmailPort;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Solo desarrollo: entrega el correo al buzón local de pruebas (Mailpit). Nunca en producción.
 */
final class DevInboxEmailAdapter implements EmailPort
{
    public function __construct(private readonly IntegrationCallRecorder $recorder) {}

    public function providerName(): string
    {
        return 'dev_inbox_email';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function send(string $to, string $subject, string $body, array $attachments = []): string
    {
        $start = hrtime(true);
        Mail::raw($body, function (Message $m) use ($to, $subject): void {
            $m->to($to)->subject($subject);
        });
        $this->recorder->record('dev_inbox_email', 'send', (hrtime(true) - $start) / 1e6, 'success', null, []);

        return 'dev_'.Str::uuid();
    }
}
