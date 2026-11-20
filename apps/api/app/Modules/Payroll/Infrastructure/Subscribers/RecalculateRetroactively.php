<?php

namespace App\Modules\Payroll\Infrastructure\Subscribers;

use App\Modules\Payroll\Application\RetroactiveRecalculation;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * NOM-7 · Un cambio con fecha pasada (employment.retroactive_change, HIS-4) recalcula en sombra los periodos
 * cerrados afectados y deja las diferencias en el periodo abierto. Repetir el evento deja lo mismo.
 */
final class RecalculateRetroactively implements DomainEventSubscriber
{
    public function __construct(private readonly RetroactiveRecalculation $retro) {}

    public function consumerName(): string
    {
        return 'payroll.retroactive_recalculation';
    }

    public function handles(): array
    {
        return [DomainEventTypes::EMPLOYMENT_RETROACTIVE_CHANGE];
    }

    public function handle(OutboxEvent $event): void
    {
        $employment = (string) ($event->payload['employment_id'] ?? '');
        $from = (string) ($event->payload['affected_from'] ?? '');
        if ($employment === '' || $from === '') {
            return;
        }
        $to = $event->payload['affected_to'] ?? null;
        TenancyScope::runAs($event->tenant_id, fn () => $this->retro->recalculate($employment, $from, is_string($to) ? $to : null, false,
            (string) trans('payroll.retroactive.from_event')));
    }
}
