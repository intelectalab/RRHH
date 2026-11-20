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
 * SEG-2 · Envía por correo el código de ingreso o de activación (regla 8 de CLAUDE.md). Por SMS o
 * WhatsApp el código ya no pasa por aquí: va por la capa de mensajería (NOT-5, MessageSender).
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
            if ((string) $payload['channel'] !== 'email') {
                return;
            }
            /** @var UserIdentity|null $identity */
            $identity = UserIdentity::query()->where('user_id', (string) $payload['user_id'])->where('kind', 'email')->first();
            if ($identity === null) {
                return;
            }
            $key = match ($payload['purpose']) {
                'invite' => 'identity.codes.activation_message',
                'signature_otp' => 'identity.codes.signature_message',
                default => 'identity.codes.login_message',
            };
            $text = (string) trans($key, [
                'code' => Crypt::decryptString((string) $payload['code_enc']),
                'minutes' => (int) $payload['minutes'],
                'hours' => intdiv((int) $payload['minutes'], 60),
            ]);

            $this->integrations->email()->send($identity->identifier(), (string) trans('identity.codes.email_subject'), $text);
        });
    }
}
