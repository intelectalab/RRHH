<?php

namespace App\Modules\Platform\Infrastructure\Subscribers;

use App\Modules\Platform\Application\Billing\SaasBilling;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * SAS-11 · Envía el comprobante del SaaS al proveedor de facturación electrónica (evento saas_invoice.issue_requested).
 * El comprobante es de la plataforma: se trabaja sin organización activa. El servicio solo envía lo que sigue
 * pendiente, así una entrega doble no emite dos comprobantes; si el proveedor falla, el outbox reintenta.
 */
final class DeliverSaasInvoice implements DomainEventSubscriber
{
    public function __construct(private readonly SaasBilling $billing) {}

    public function consumerName(): string
    {
        return 'platform.deliver_saas_invoice';
    }

    public function handles(): array
    {
        return [DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        $id = (string) ($event->payload['invoice_id'] ?? '');
        if ($id === '') {
            return;
        }
        TenancyScope::runWithoutTenant(fn () => $this->billing->deliver($id));
    }
}
