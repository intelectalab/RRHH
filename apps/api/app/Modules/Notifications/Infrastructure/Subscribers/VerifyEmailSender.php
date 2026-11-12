<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Modules\Notifications\Infrastructure\Models\MessageChannel;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use RuntimeException;

/**
 * NOT-3 · Pregunta al proveedor por el remitente propio. Verificado: el canal queda activo y los correos
 * salen con él. Rechazado: queda el de la plataforma y se anota el motivo. Pendiente (DNS sin propagar):
 * el outbox vuelve a preguntar con espera creciente.
 */
final class VerifyEmailSender implements DomainEventSubscriber
{
    public function __construct(private readonly IntegrationManager $integrations) {}

    public function consumerName(): string
    {
        return 'notifications.verify_email_sender';
    }

    public function handles(): array
    {
        return [DomainEventTypes::EMAIL_SENDER_VERIFICATION_REQUESTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            /** @var MessageChannel|null $row */
            $row = MessageChannel::query()->whereKey((string) ($event->payload['channel_id'] ?? ''))->lockForUpdate()->first();
            if ($row === null || $row->sender_identity === null || $row->verification_status !== 'pending') {
                return;
            }
            $status = $this->integrations->email()->verifySender($row->sender_identity);
            if ($status === 'verified') {
                $row->fill(['status' => 'active', 'verification_status' => 'verified', 'verified_at' => now(), 'verification_error' => null])->save();
            } elseif ($status === 'failed') {
                $row->fill(['status' => 'inactive', 'verification_status' => 'failed', 'verification_error' => 'rejected_by_provider'])->save();
            } else {
                throw new RuntimeException('sender_verification_pending');
            }
        });
    }
}
