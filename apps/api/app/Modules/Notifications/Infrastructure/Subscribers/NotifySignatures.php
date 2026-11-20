<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * DOC-6 · Avisos del flujo de firma: al firmante de turno cuando le toca (o se le recuerda) y a quien envió el
 * documento cuando se completa o se rechaza.
 */
final class NotifySignatures implements DomainEventSubscriber
{
    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.signatures';
    }

    public function handles(): array
    {
        return [DomainEventTypes::SIGNATURE_SENT, DomainEventTypes::SIGNATURE_COMPLETED, DomainEventTypes::SIGNATURE_DECLINED];
    }

    public function handle(OutboxEvent $event): void
    {
        /** @var array<string, mixed> $p */
        $p = $event->payload;
        $users = array_values(array_map('strval', (array) ($p['user_ids'] ?? [])));
        if ($users === []) {
            return;
        }
        [$category, $variables] = match ($event->event_type) {
            DomainEventTypes::SIGNATURE_SENT => ['signatures.requested', ['document' => (string) ($p['title'] ?? '')]],
            DomainEventTypes::SIGNATURE_COMPLETED => ['signatures.completed', ['document' => (string) ($p['title'] ?? '')]],
            default => ['signatures.declined', ['document' => (string) ($p['title'] ?? ''), 'signer' => (string) ($p['signer'] ?? ''), 'reason' => (string) ($p['reason'] ?? '')]],
        };
        TenancyScope::runAs($event->tenant_id, fn () => $this->notifier->notify($category, $users, $variables, 'signature_request', $event->aggregate_id));
    }
}
