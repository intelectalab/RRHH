<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * SAS-6 · RN-SAS-02 · Avisa a los administradores de la organización cuando el consumo llega al porcentaje
 * de aviso o al tope del plan. El servicio de consumo emite cada evento una sola vez por cruce de umbral.
 */
final class NotifyUsageLimit implements DomainEventSubscriber
{
    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.usage_limit';
    }

    public function handles(): array
    {
        return [DomainEventTypes::LIMIT_WARNING, DomainEventTypes::LIMIT_REACHED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array{metric: string, current: string, limit: string|null, percent: int|null} $p */
            $p = $event->payload;
            $category = $event->event_type === DomainEventTypes::LIMIT_REACHED ? 'limits.reached' : 'limits.warning';
            $key = 'usage.metrics.'.$p['metric'];
            $label = trans($key);

            $roleId = DB::table('roles')->where('system_role_code', 'admin_empresa')->whereNull('deleted_at')->value('id');
            $users = $roleId === null ? [] : $this->notifier->usersWithRole((string) $roleId);
            $this->notifier->notify($category, $users, [
                'metric' => is_string($label) && $label !== $key ? $label : $p['metric'],
                'current' => (string) $p['current'],
                'limit' => (string) ($p['limit'] ?? ''),
                'percent' => (string) ($p['percent'] ?? ''),
            ], 'tenant_limit', $event->aggregate_id);
        });
    }
}
