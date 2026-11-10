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
 * Envía el enlace de invitación por el puerto de correo (regla 8 de CLAUDE.md).
 */
final class SendInvitation implements DomainEventSubscriber
{
    public function __construct(private readonly IntegrationManager $integrations) {}

    public function consumerName(): string
    {
        return 'identity.send_invitation';
    }

    public function handles(): array
    {
        return [DomainEventTypes::USER_INVITED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array<string, string> $payload */
            $payload = $event->payload;

            /** @var UserIdentity|null $identity */
            $identity = UserIdentity::query()->where('user_id', $payload['user_id'])->where('kind', 'email')->first();
            if ($identity === null) {
                return;
            }

            $link = rtrim($payload['origin'] ?? '', '/').'/activar-cuenta?token='.urlencode(Crypt::decryptString($payload['token_enc']));

            $this->integrations->email()->send(
                $identity->identifier(),
                (string) trans('identity.invitation.subject'),
                (string) trans('identity.invitation.body', [
                    'link' => $link,
                    'hours' => (int) config('identity.invitation_ttl_hours'),
                ]),
            );
        });
    }
}
