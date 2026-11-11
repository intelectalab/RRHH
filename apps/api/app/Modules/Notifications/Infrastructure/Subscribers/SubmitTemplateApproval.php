<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Modules\Notifications\Application\TemplateService;
use App\Modules\Notifications\Infrastructure\Models\NotificationTemplate;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * NOT-5 · Envía la plantilla de WhatsApp al proveedor configurado y aplica su respuesta. Si el
 * proveedor responde «pendiente», la plantilla sigue así hasta su aviso (webhook de M-WHA).
 */
final class SubmitTemplateApproval implements DomainEventSubscriber
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly TemplateService $templates,
    ) {}

    public function consumerName(): string
    {
        return 'notifications.submit_template_approval';
    }

    public function handles(): array
    {
        return [DomainEventTypes::MESSAGE_TEMPLATE_APPROVAL_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array{template_id: string} $payload */
            $payload = $event->payload;
            /** @var NotificationTemplate|null $template */
            $template = NotificationTemplate::query()->whereKey($payload['template_id'])->lockForUpdate()->first();
            if ($template === null || $template->approval_status !== 'pending') {
                return;
            }
            /** @var string|null $provider */
            $provider = DB::table('message_channels')->where('channel', 'whatsapp')->where('status', 'active')->orderBy('created_at')->value('provider');
            $decision = $this->integrations->messagingFor($provider)->submitTemplate($template->code, $template->locale, $template->body);
            $this->templates->applyDecision($template, $decision);
        });
    }
}
