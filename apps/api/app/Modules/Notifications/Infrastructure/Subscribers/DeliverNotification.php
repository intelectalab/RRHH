<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Throwable;

/**
 * NOT-1 · NOT-3 · Entrega por canal externo a través del puerto (regla 8). Cada entrega guarda su
 * estado y error; una entrega ya enviada no se repite aunque el evento se reprocese.
 */
final class DeliverNotification implements DomainEventSubscriber
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly TemplateRenderer $renderer,
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

            $text = $this->renderer->render($payload['category'], $delivery->channel, $payload['locale'], $payload['variables']);
            $port = $this->integrations->email();
            try {
                $messageId = $port->send(FieldCipher::decrypt((string) $delivery->recipient_address_enc), $text['subject'], $text['body']);
                $delivery->forceFill(['status' => 'sent', 'provider' => $port->providerName(), 'provider_message_id' => $messageId, 'sent_at' => now(), 'attempts' => $delivery->attempts + 1, 'error' => null])->save();
            } catch (Throwable $e) {
                // RN-NOT-06: el outbox reintenta con espera creciente; el aviso en la aplicación ya existe.
                $delivery->forceFill(['status' => 'failed', 'provider' => $port->providerName(), 'attempts' => $delivery->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

                throw $e;
            }
        });
    }
}
