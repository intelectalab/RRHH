<?php

namespace App\Shared\Integrations\Email;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\EmailPort;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * QA-17 · Correo real por el SMTP de la organización que opera la plataforma (config/mail.php, variables
 * MAIL_*). Se activa con EMAIL_PROVIDER=smtp. Un SMTP no confirma remitentes propios ni envía avisos de
 * rebote por webhook: los remitentes propios quedan «pending» (se usa el de la plataforma) y no hay avisos.
 */
final class SmtpEmailAdapter implements EmailPort
{
    public function __construct(private readonly IntegrationCallRecorder $recorder) {}

    public function providerName(): string
    {
        return 'smtp';
    }

    public function isFake(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $attachments  nombre del archivo => contenido
     */
    public function send(string $to, string $subject, string $body, array $attachments = [], ?string $from = null): string
    {
        $start = hrtime(true);
        $reference = 'smtp_'.Str::uuid();
        try {
            Mail::raw($body, function (Message $m) use ($to, $subject, $attachments, $reference): void {
                $m->to($to)->subject($subject);
                $m->getHeaders()->addTextHeader('X-Gestia-Reference', $reference);
                foreach ($attachments as $name => $content) {
                    if (is_string($content)) {
                        $m->attachData($content, (string) $name);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->recorder->record('smtp', 'send', (hrtime(true) - $start) / 1e6, 'failure', $e::class, []);
            throw $e;
        }
        $this->recorder->record('smtp', 'send', (hrtime(true) - $start) / 1e6, 'success', null, []);

        return $reference;
    }

    /** Un SMTP no puede confirmar que un dominio ajeno autorizó el envío: se sigue usando el remitente de la plataforma. */
    public function verifySender(string $address): string
    {
        return 'pending';
    }

    /** Un SMTP no envía avisos de rebote por webhook. */
    public function parseFeedback(string $payload, array $headers): array
    {
        return [];
    }
}
