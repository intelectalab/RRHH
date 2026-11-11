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
 * SEG-2 · Envía el código de ingreso o de activación por el puerto de mensajería (SMS/WhatsApp)
 * o de correo, según el canal (regla 8 de CLAUDE.md).
 */
final class SendMessageCode implements DomainEventSubscriber
{
    public function __construct(private readonly IntegrationManager $integrations) {}

    public function consumerName(): string
    {
        return 'identity.send_message_code';
    }

    public function handles(): array
    {
        return [DomainEventTypes::USER_MESSAGE_CODE_ISSUED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var array<string, mixed> $payload */
            $payload = $event->payload;
            $channel = (string) $payload['channel'];
            /** @var UserIdentity|null $identity */
            $identity = UserIdentity::query()->where('user_id', (string) $payload['user_id'])
                ->where('kind', $channel === 'email' ? 'email' : 'phone')->first();
            if ($identity === null) {
                return;
            }
            $key = $payload['purpose'] === 'invite' ? 'identity.codes.activation_message' : 'identity.codes.login_message';
            $text = (string) trans($key, [
                'code' => Crypt::decryptString((string) $payload['code_enc']),
                'minutes' => (int) $payload['minutes'],
                'hours' => intdiv((int) $payload['minutes'], 60),
            ]);

            if ($channel === 'email') {
                $this->integrations->email()->send($identity->identifier(), (string) trans('identity.codes.email_subject'), $text);

                return;
            }
            $this->integrations->messaging()->sendMessage('+'.$identity->identifier(), $text);
        });
    }
}
