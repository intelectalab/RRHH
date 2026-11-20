<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Modules\Notifications\Application\Email\EmailSenderSettings;
use App\Modules\Notifications\Application\Push\PushDevices;
use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Security\BlindIndex;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOT-1 · NOT-3 · Entrega por canal externo a través del puerto (regla 8). Cada entrega guarda su
 * estado y error; una entrega ya enviada no se repite aunque el evento se reprocese. Sale con el
 * remitente propio verificado de la organización (o el de la plataforma) y nunca a una dirección que
 * rebotó mientras esperaba (queda «suprimida»).
 */
final class DeliverNotification implements DomainEventSubscriber
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly TemplateRenderer $renderer,
        private readonly EmailSenderSettings $sender,
        private readonly PushDevices $push,
    ) {}

    public function consumerName(): string
    {
        return 'notifications.deliver';
    }

    public function handles(): array
    {
        return [DomainEventTypes::NOTIFICATION_DELIVERY_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array{delivery_id: string, category: string, locale: string, variables: array<string, string|int|null>} $payload */
            $payload = $event->payload;
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()->whereKey($payload['delivery_id'])->lockForUpdate()->first();
            if ($delivery === null || ! in_array($delivery->status, ['queued', 'failed'], true)) {
                return;
            }

            if ($delivery->channel === 'push') {
                $this->deliverPush($delivery, $payload);

                return;
            }

            $address = FieldCipher::decrypt((string) $delivery->recipient_address_enc);
            $bounced = DB::table('user_identities')->where('kind', 'email')->where('identifier_hash', BlindIndex::for('email', $address))
                ->whereNotNull('bounced_at')->exists();
            if ($bounced) {
                $delivery->forceFill(['status' => 'suppressed', 'error' => 'address_bounced'])->save();

                return;
            }

            $text = $this->renderer->render($payload['category'], $delivery->channel, $payload['locale'], $payload['variables']);
            $port = $this->integrations->email();
            try {
                $messageId = $port->send($address, $text['subject'], $text['body'], [], $this->sender->fromAddress());
                $delivery->forceFill(['status' => 'sent', 'provider' => $port->providerName(), 'provider_message_id' => $messageId, 'sent_at' => now(), 'attempts' => $delivery->attempts + 1, 'error' => null])->save();
            } catch (Throwable $e) {
                // RN-NOT-06: el outbox reintenta con espera creciente; el aviso en la aplicación ya existe.
                $delivery->forceFill(['status' => 'failed', 'provider' => $port->providerName(), 'attempts' => $delivery->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

                throw $e;
            }
        });
    }

    /**
     * NOT-4 · El aviso push solo invita a abrir la aplicación en la pantalla del aviso: ni nombres ni montos.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliverPush(NotificationDelivery $delivery, array $payload): void
    {
        $locale = (string) ($payload['locale'] ?? 'es');
        $variables = (array) ($payload['variables'] ?? []);
        $message = [
            'title' => (string) trans('notifications.push.title', ['organization' => (string) ($variables['organization'] ?? '')], $locale),
            'body' => (string) trans('notifications.push.body', [], $locale),
            'url' => (string) ($payload['link'] ?? '/'),
            'tag' => (string) ($payload['category'] ?? 'notification'),
        ];
        try {
            $result = $this->push->deliver(FieldCipher::decrypt((string) $delivery->recipient_address_enc), $message);
            $delivery->forceFill(['status' => $result['status'], 'provider' => $result['provider'], 'error' => $result['error'], 'attempts' => $delivery->attempts + 1]
                + ($result['status'] === 'sent' ? ['sent_at' => now()] : []))->save();
        } catch (Throwable $e) {
            $delivery->forceFill(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }
    }
}
