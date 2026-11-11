<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Modules\Notifications\Application\TemplateRenderer;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Messaging\OutboundMessage;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * NOT-5 · Envía un mensaje directo por el adaptador del proveedor que la organización configuró para
 * el canal (message_channels; sin configuración, el adaptador por defecto). WhatsApp exige plantilla
 * aprobada: sin ella el mensaje sale por SMS y la entrega lo anota (fallback_from). Una entrega ya
 * enviada no se repite aunque el evento se reprocese, y la referencia al proveedor es el id de la
 * entrega (idempotente también del lado del proveedor).
 */
final class DeliverMessage implements DomainEventSubscriber
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function consumerName(): string
    {
        return 'notifications.deliver_message';
    }

    public function handles(): array
    {
        return [DomainEventTypes::MESSAGE_SEND_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array{delivery_id: string, template_code: string, locale: string, variables_enc: string} $payload */
            $payload = $event->payload;
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()->whereKey($payload['delivery_id'])->lockForUpdate()->first();
            if ($delivery === null || ! in_array($delivery->status, ['queued', 'failed'], true)) {
                return;
            }

            $channel = $delivery->channel;
            $providerTemplate = null;
            if ($channel === 'whatsapp') {
                $providerTemplate = $this->approvedTemplateRef($payload['template_code'], $payload['locale']);
                if ($providerTemplate === null) {
                    $delivery->forceFill(['channel' => 'sms', 'fallback_from' => 'whatsapp'])->save();
                    $channel = 'sms';
                }
            }

            /** @var array<string, string|int|null> $variables */
            $variables = (array) json_decode(Crypt::decryptString($payload['variables_enc']), true);
            $text = $this->renderer->render($payload['template_code'], $channel, $payload['locale'], $variables);
            $port = $this->integrations->messagingFor($this->provider($channel));
            $message = new OutboundMessage($channel, FieldCipher::decrypt((string) $delivery->recipient_address_enc), $text['body'], $delivery->id, $providerTemplate);
            try {
                $id = $port->send($message);
                $delivery->forceFill(['status' => 'sent', 'provider' => $port->providerName(), 'provider_message_id' => $id, 'sent_at' => now(), 'attempts' => $delivery->attempts + 1, 'error' => null])->save();
            } catch (Throwable $e) {
                // RN-NOT-06: el outbox reintenta con espera creciente.
                $delivery->forceFill(['status' => 'failed', 'provider' => $port->providerName(), 'attempts' => $delivery->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

                throw $e;
            }
        });
    }

    private function approvedTemplateRef(string $code, string $locale): ?string
    {
        /** @var NotificationTemplate|null $template */
        $template = NotificationTemplate::query()->where('code', $code)->where('channel', 'whatsapp')->where('locale', $locale)
            ->where('approval_status', 'approved')->first();

        if ($template === null) {
            return null;
        }

        return $template->provider_template_ref ?? $template->id;
    }

    private function provider(string $channel): ?string
    {
        /** @var string|null */
        return DB::table('message_channels')->where('channel', $channel)->where('status', 'active')->orderBy('created_at')->value('provider');
    }
}
