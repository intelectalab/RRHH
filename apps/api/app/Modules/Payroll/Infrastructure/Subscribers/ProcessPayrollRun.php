<?php

namespace App\Modules\Payroll\Infrastructure\Subscribers;

use App\Modules\Payroll\Application\PayrollRuns;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * NOM-8 · Procesa en segundo plano una corrida de planilla en cola. Solo calcula las relaciones que faltan:
 * una entrega doble del evento o una reanudación no repite trabajo ni duplica filas.
 */
final class ProcessPayrollRun implements DomainEventSubscriber
{
    public function __construct(private readonly PayrollRuns $runs) {}

    public function consumerName(): string
    {
        return 'payroll.process_run';
    }

    public function handles(): array
    {
        return [DomainEventTypes::PAYROLL_RUN_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        $id = (string) ($event->payload['payroll_run_id'] ?? '');
        if ($id !== '') {
            TenancyScope::runAs($event->tenant_id, fn () => $this->runs->process($id));
        }
    }
}
