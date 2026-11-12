<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * NOT-3 · Avisa a RRHH (quien ve el registro de entregas) que la dirección de correo de una persona rebotó,
 * para que la corrija. El aviso no lleva la dirección.
 */
final class NotifyEmailBounce implements DomainEventSubscriber
{
    public const CATEGORY = 'notifications.email_bounced';

    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.notify_email_bounce';
    }

    public function handles(): array
    {
        return [DomainEventTypes::EMAIL_ADDRESS_BOUNCED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $userId = (string) ($event->payload['user_id'] ?? '');
            $name = DB::table('users')->where('id', $userId)->value('display_name');
            if (! is_string($name)) {
                return;
            }
            $recipients = array_values(array_diff($this->hrUsers(), [$userId]));
            $this->notifier->notify(self::CATEGORY, $recipients, [
                'user' => $name,
                'reason' => (string) ($event->payload['kind'] ?? 'bounce'),
                'delivery_id' => (string) ($event->payload['delivery_id'] ?? ''),
            ], 'user', $userId);
        });
    }

    /**
     * Usuarios activos con un rol vigente que trae notifications.deliveries.view.
     *
     * @return list<string>
     */
    private function hrUsers(): array
    {
        $today = now()->toDateString();

        $ids = DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNull('r.deleted_at')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('rp.permission_code', 'notifications.deliveries.view')
            ->where('u.status', 'active')->whereNull('u.deleted_at')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->distinct()->pluck('u.id')->map(fn ($id): string => (string) $id)->all();

        return array_values($ids);
    }
}
