<?php

namespace App\Modules\Reports\Infrastructure\Subscribers;

use App\Modules\Reports\Application\ExportService;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * UI-11 · Genera en segundo plano una exportación grande (evento export.requested). El servicio solo toma
 * trabajos en cola, así una entrega doble del evento no genera dos archivos.
 */
final class RunExportJob implements DomainEventSubscriber
{
    public function __construct(private readonly ExportService $exports) {}

    public function consumerName(): string
    {
        return 'reports.run_export';
    }

    public function handles(): array
    {
        return [DomainEventTypes::EXPORT_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        $id = (string) ($event->payload['export_id'] ?? '');
        if ($id === '') {
            return;
        }
        $entity = $event->payload['legal_entity_id'] ?? null;
        $ip = $event->payload['ip'] ?? null;
        TenancyScope::runAs($event->tenant_id, fn () => $this->exports->run($id, is_string($entity) ? $entity : null, is_string($ip) ? $ip : null));
    }
}
