<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * SAS-2 · Avisa a los administradores de la organización cuando la consola cambia su estado. El aviso
 * es obligatorio y sale también por correo: con la organización suspendida no pueden ingresar a verlo.
 */
final class NotifyOrganizationStatus implements DomainEventSubscriber
{
    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.organization_status';
    }

    public function handles(): array
    {
        return [DomainEventTypes::TENANT_STATUS_CHANGED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array{from_status: string|null, to_status: string, reason: string|null, retention_until?: string|null} $p */
            $p = $event->payload;
            $category = match ($p['to_status']) {
                'suspended', 'blocked' => 'organization.suspended',
                'cancelled' => 'organization.cancelled',
                'active' => $p['from_status'] === 'trial' ? 'organization.activated' : 'organization.reactivated',
                default => null,
            };
            if ($category === null) {
                return;
            }

            $roleId = DB::table('roles')->where('system_role_code', 'admin_empresa')->whereNull('deleted_at')->value('id');
            $users = $roleId === null ? [] : $this->notifier->usersWithRole((string) $roleId);
            $this->notifier->notify($category, $users, [
                'reason' => (string) ($p['reason'] ?? ''),
                'retention_until' => (string) ($p['retention_until'] ?? ''),
            ], 'tenant', $event->tenant_id);
        });
    }
}
