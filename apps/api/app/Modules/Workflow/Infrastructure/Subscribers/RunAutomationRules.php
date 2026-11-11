<?php

namespace App\Modules\Workflow\Infrastructure\Subscribers;

use App\Modules\Workflow\Application\AutomationCatalog;
use App\Modules\Workflow\Application\AutomationRunner;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * WFL-5 · Escucha todos los eventos que el catálogo ofrece como disparador y corre las reglas activas.
 * Doble idempotencia: el despacho no repite el evento para este consumidor y el ejecutor no repite
 * una regla para el mismo evento (workflow.automation:{regla}).
 */
final class RunAutomationRules implements DomainEventSubscriber
{
    public function __construct(
        private readonly AutomationRunner $runner,
        private readonly AutomationCatalog $catalog,
    ) {}

    public function consumerName(): string
    {
        return 'workflow.automation_rules';
    }

    public function handles(): array
    {
        return $this->catalog->triggerEvents();
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $this->runner->dispatch($event->tenant_id, $event->id, $event->event_type, $event->payload);
        });
    }
}
