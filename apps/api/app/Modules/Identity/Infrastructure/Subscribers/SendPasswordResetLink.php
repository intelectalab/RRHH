<?php

namespace App\Modules\Identity\Infrastructure\Subscribers;

use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\Crypt;

/**
 * Envía el enlace de recuperación por el puerto de correo (regla 8 de CLAUDE.md).
 */
final class SendPasswordResetLink implements DomainEventSubscriber
{
    public function __construct(private readonly IntegrationManager $integrations) {}

    public function consumerName(): string
    {
        return 'identity.send_password_reset_link';
    }

    public function handles(): array
    {
        return [DomainEventTypes::USER_PASSWORD_RESET_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array<string, string> $payload */
            $payload = $event->payload;

            /** @var UserIdentity|null $identity */
            $identity = UserIdentity::query()
                ->where('user_id', $payload['user_id'])
                ->where('kind', 'email')
                ->orderByDesc('is_primary')
                ->first();
            if ($identity === null) {
                return;
            }

            $token = Crypt::decryptString($payload['token_enc']);
            $link = rtrim($payload['origin'] ?? '', '/').'/restablecer-clave?token='.urlencode($token);

            $this->integrations->email()->send(
                $identity->identifier(),
                (string) trans('identity.password_reset.subject'),
                (string) trans('identity.password_reset.body', [
                    'link' => $link,
                    'minutes' => (int) config('identity.password_reset_ttl_minutes'),
                ]),
            );
        });
    }
}
