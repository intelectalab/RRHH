<?php

namespace App\Modules\Imports\Infrastructure\Subscribers;

use App\Modules\Imports\Application\ImportRunner;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * IMP-1 · Procesa en segundo plano la importación confirmada (evento import.started).
 * El despacho no repite el evento para este consumidor y el ejecutor ignora importaciones que
 * ya no están «importing», así que una entrega doble no duplica registros.
 */
final class RunImport implements DomainEventSubscriber
{
    public function __construct(private readonly ImportRunner $runner) {}

    public function consumerName(): string
    {
        return 'imports.run_import';
    }

    public function handles(): array
    {
        return [DomainEventTypes::IMPORT_STARTED];
    }

    public function handle(OutboxEvent $event): void
    {
        $jobId = (string) ($event->payload['import_job_id'] ?? '');
        if ($jobId === '') {
            return;
        }
        TenancyScope::runAs($event->tenant_id, fn () => $this->runner->run($jobId));
    }
}
