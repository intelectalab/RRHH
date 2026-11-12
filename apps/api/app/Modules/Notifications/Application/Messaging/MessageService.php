<?php

namespace App\Modules\Notifications\Application\Messaging;

use App\Modules\Notifications\Application\NotificationCatalog;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Messaging\InboundMessage;
use App\Shared\Messaging\MessageSender;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\FieldCipher;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * NOT-5 · Implementación de la capa de mensajería. Registra la entrega (número cifrado, propósito,
 * plantilla y clave de idempotencia) y deja el envío en el outbox en la misma transacción que quien
 * lo pide. El texto se arma al enviar, con la plantilla de la organización en el idioma pedido.
 */
final class MessageService implements MessageSender
{
    public const CHANNELS = ['sms', 'whatsapp'];

    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly OutboxWriter $outbox,
    ) {}

    public function send(string $channel, string $to, string $templateCode, array $variables, string $locale, string $purpose, string $idempotencyKey, ?\DateTimeInterface $notBefore = null): string
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Canal de mensajería no admitido: {$channel}");
        }
        if (! $this->catalog->isMessage($templateCode)) {
            throw new InvalidArgumentException("Plantilla de mensaje desconocida: {$templateCode}");
        }
        if (preg_match('/^\+[1-9]\d{6,14}$/', $to) !== 1) {
            throw new InvalidArgumentException('El número debe estar en formato E.164.');
        }

        /** @var string|null $existing */
        $existing = NotificationDelivery::query()->where('idempotency_key', $idempotencyKey)->value('id');
        if ($existing !== null) {
            return $existing;
        }

        $delivery = NotificationDelivery::create([
            'channel' => $channel,
            'recipient_address_enc' => FieldCipher::encrypt($to),
            'status' => 'queued',
            'attempts' => 0,
            'purpose' => $purpose,
            'template_code' => $templateCode,
            'idempotency_key' => $idempotencyKey,
        ]);
        $event = $this->outbox->record(DomainEventTypes::MESSAGE_SEND_REQUESTED, 'notification_delivery', $delivery->id, [
            'delivery_id' => $delivery->id,
            'template_code' => $templateCode,
            'locale' => in_array($locale, NotificationCatalog::LOCALES, true) ? $locale : 'es',
            'variables_enc' => Crypt::encryptString((string) json_encode($variables)),
        ]);
        if ($notBefore !== null) {
            $event->forceFill(['next_attempt_at' => $notBefore])->save(); // RN-NOT-03
        } else {
            $this->outbox->deliverSoon($event);
        }

        return $delivery->id;
    }

    /**
     * Mensaje entrante ya normalizado por el adaptador: queda como evento para quien lo atienda
     * (autoservicio por WhatsApp, M-WHA). Remitente y texto viajan cifrados.
     */
    public function receive(InboundMessage $message, string $provider): void
    {
        DB::transaction(function () use ($message, $provider): void {
            $this->outbox->record(DomainEventTypes::MESSAGE_RECEIVED, 'inbound_message', (string) Str::uuid(), [
                'channel' => $message->channel,
                'from_enc' => FieldCipher::encrypt($message->from),
                'body_enc' => FieldCipher::encrypt($message->body),
                'provider' => $provider,
                'provider_message_id' => $message->providerMessageId,
            ]);
        });
    }
}
