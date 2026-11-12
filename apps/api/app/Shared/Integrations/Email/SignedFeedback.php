<?php

namespace App\Shared\Integrations\Email;

/**
 * NOT-3 · Formato de avisos de los adaptadores de desarrollo y pruebas: JSON
 * {"events":[{"message_id":"…","type":"bounce|soft_bounce|complaint|delivered","reason":"…"}]}
 * firmado con HMAC-SHA256 en la cabecera X-Gestia-Signature (secreto config integrations.email_webhook_secret).
 * Un proveedor real trae su propio formato y su propia firma en su adaptador.
 */
final class SignedFeedback
{
    public const HEADER = 'x-gestia-signature';

    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<EmailFeedbackEvent>
     */
    public static function parse(string $payload, array $headers): array
    {
        $signature = $headers[self::HEADER] ?? '';
        if (self::secret() === '' || ! hash_equals(self::sign($payload), $signature)) {
            throw new InvalidWebhookSignature('invalid_signature');
        }
        $data = json_decode($payload, true);
        $events = [];
        foreach ((array) (is_array($data) ? ($data['events'] ?? []) : []) as $e) {
            if (! is_array($e) || ! is_string($e['message_id'] ?? null) || ! in_array($e['type'] ?? null, EmailFeedbackEvent::KINDS, true)) {
                continue;
            }
            $reason = is_string($e['reason'] ?? null) ? mb_substr($e['reason'], 0, 300) : null;
            $events[] = new EmailFeedbackEvent($e['message_id'], (string) $e['type'], $reason);
        }

        return $events;
    }

    private static function secret(): string
    {
        return (string) config('integrations.email_webhook_secret', '');
    }
}
