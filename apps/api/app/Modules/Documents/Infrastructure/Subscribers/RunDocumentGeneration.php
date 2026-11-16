<?php

namespace App\Modules\Documents\Infrastructure\Subscribers;

use App\Modules\Documents\Application\DocumentGenerator;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * DOC-5 · Procesa en segundo plano un lote de generación (evento document.generation_started). El generador solo
 * toma lotes en cola, así que una entrega doble no duplica documentos.
 */
final class RunDocumentGeneration implements DomainEventSubscriber
{
    public function __construct(private readonly DocumentGenerator $generator) {}

    public function consumerName(): string
    {
        return 'documents.run_generation';
    }

    public function handles(): array
    {
        return [DomainEventTypes::DOCUMENT_GENERATION_STARTED];
    }

    public function handle(OutboxEvent $event): void
    {
        $batchId = (string) ($event->payload['batch_id'] ?? '');
        if ($batchId === '') {
            return;
        }
        $ip = $event->payload['ip'] ?? null;
        TenancyScope::runAs($event->tenant_id, fn () => $this->generator->run($batchId, is_string($ip) ? $ip : null));
    }
}
