<?php

namespace App\Modules\Vacations\Infrastructure\Subscribers;

use App\Modules\Vacations\Application\VacationAccrual;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;

/**
 * VAC-2 · M-VAC §11. Con el ingreso o reingreso se abre el primer periodo vacacional; con el cese, los
 * días pendientes pasan a la liquidación y el saldo queda en cero (RN-VAC-12). Idempotente: el despacho
 * no repite el evento para este consumidor y el libro no repite movimientos (claves por periodo).
 */
final class SyncVacationsOnEmploymentEvents implements DomainEventSubscriber
{
    public function __construct(private readonly VacationAccrual $accrual) {}

    public function consumerName(): string
    {
        return 'vacations.employment_events';
    }

    public function handles(): array
    {
        return [DomainEventTypes::EMPLOYEE_HIRED, DomainEventTypes::EMPLOYEE_REHIRED, DomainEventTypes::EMPLOYEE_TERMINATED];
    }

    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;
        $employmentId = (string) ($payload['employment_id'] ?? $payload['employee_id'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/i', $employmentId) !== 1) {
            return;
        }
        TenancyScope::runAs($event->tenant_id, function () use ($event, $payload, $employmentId): void {
            if ($event->event_type === DomainEventTypes::EMPLOYEE_TERMINATED) {
                $this->accrual->settle($employmentId, (string) ($payload['termination_date'] ?? now()->toDateString()), null);

                return;
            }
            $this->accrual->sync($employmentId);
        });
    }
}
