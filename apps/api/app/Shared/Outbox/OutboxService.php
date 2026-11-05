<?php

namespace App\Shared\Outbox;

use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OutboxService implements OutboxWriter
{
    /**
     * @var array<string, array<int, DomainEventSubscriber>>
     */
    private array $subscribers = [];

    public function __construct(
        private readonly ProcessedEventTracker $tracker
    ) {}

    /**
     * Registra un evento en la tabla outbox_events dentro de la transacción activa.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $tenantId = null,
        ?string $correlationId = null
    ): OutboxEvent {
        // Valida el payload contra el catálogo versionado
        DomainEventTypes::validatePayload($eventType, $payload);

        $resolvedTenantId = $tenantId ?? TenancyScope::current();

        if (empty($resolvedTenantId)) {
            throw new InvalidArgumentException('tenant_id es obligatorio para registrar un evento de outbox.');
        }

        return OutboxEvent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $resolvedTenantId,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => $payload,
            'occurred_at' => now(),
            'status' => OutboxEvent::STATUS_PENDING,
            'attempts' => 0,
            'correlation_id' => $correlationId,
        ]);
    }

    public function subscribe(DomainEventSubscriber $subscriber): void
    {
        foreach ($subscriber->handles() as $eventType) {
            $this->subscribers[$eventType][] = $subscriber;
        }
    }

    /**
     * @return array<int, DomainEventSubscriber>
     */
    public function getSubscribersFor(string $eventType): array
    {
        return $this->subscribers[$eventType] ?? [];
    }

    public function getTracker(): ProcessedEventTracker
    {
        return $this->tracker;
    }
}
