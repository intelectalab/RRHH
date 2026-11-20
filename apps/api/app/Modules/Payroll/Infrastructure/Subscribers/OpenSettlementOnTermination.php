<?php

namespace App\Modules\Payroll\Infrastructure\Subscribers;

use App\Modules\Payroll\Application\Settlements\Settlements;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * LIQ-1 · RN-LIQ-01 · Al registrarse un cese (COL-11) se crea la liquidación en borrador con su fecha y motivo; si el
 * cese se revierte, la liquidación abierta se anula. Idempotente por cese.
 */
final class OpenSettlementOnTermination implements DomainEventSubscriber
{
    public function __construct(private readonly Settlements $settlements) {}

    public function consumerName(): string
    {
        return 'payroll.settlement_on_termination';
    }

    public function handles(): array
    {
        return [DomainEventTypes::OFFBOARDING_STARTED, DomainEventTypes::OFFBOARDING_CANCELLED];
    }

    public function handle(OutboxEvent $event): void
    {
        $p = (array) $event->payload;
        $termination = isset($p['termination_id']) ? (string) $p['termination_id'] : null;
        TenancyScope::runAs($event->tenant_id, function () use ($event, $p, $termination): void {
            if ($event->event_type === DomainEventTypes::OFFBOARDING_CANCELLED) {
                if ($termination !== null) {
                    $this->settlements->voidForTermination($termination);
                }

                return;
            }
            $employment = (string) ($p['employment_id'] ?? '');
            $date = (string) ($p['termination_date'] ?? '');
            if ($employment !== '' && $date !== '') {
                $this->settlements->createForTermination($employment, $termination, $date, isset($p['reason']) ? (string) $p['reason'] : null);
            }
        });
    }
}
