<?php

namespace App\Modules\Payroll\Infrastructure\Subscribers;

use App\Modules\Payroll\Application\Payslips\Payslips;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * BOL-1 · RN-BOL-01 · Al cerrar el periodo se generan sus boletas (y se publican si la organización lo tiene
 * configurado). Generar es idempotente: repetir el evento no duplica boletas.
 */
final class GeneratePayslips implements DomainEventSubscriber
{
    public function __construct(private readonly Payslips $payslips) {}

    public function consumerName(): string
    {
        return 'payroll.payslips_generation';
    }

    public function handles(): array
    {
        return [DomainEventTypes::PAYROLL_CLOSED];
    }

    public function handle(OutboxEvent $event): void
    {
        $period = (string) ($event->payload['period_id'] ?? '');
        if ($period === '') {
            return;
        }
        TenancyScope::runAs($event->tenant_id, fn () => $this->payslips->generate($period, null));
    }
}
