<?php

namespace App\Modules\Notifications\Application\Email;

use App\Modules\Notifications\Infrastructure\Models\MessageChannel;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * NOT-3 · Remitente de los correos de la organización: el de la plataforma o uno propio. El propio solo
 * se usa cuando el proveedor lo verificó (dominio con SPF/DKIM o dirección confirmada); mientras tanto,
 * y si la verificación falla, los correos salen con el remitente de la plataforma.
 */
final class EmailSenderSettings
{
    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
        private readonly TenantDirectory $tenants,
    ) {}

    /**
     * @return array{mode: string, address: string|null, verification_status: string, verified_at: string|null, verification_error: string|null, in_use: string, webhook_url: string}
     */
    public function current(): array
    {
        $row = $this->row();
        $own = $row !== null && $row->sender_identity !== null;

        return [
            'mode' => $own ? 'own' : 'platform',
            'address' => $own ? $row->sender_identity : null,
            'verification_status' => $row->verification_status ?? 'not_required',
            'verified_at' => $row?->verified_at?->toIso8601String(),
            'verification_error' => $row?->verification_error,
            // Remitente con el que salen hoy los correos.
            'in_use' => $this->fromAddress() ?? (string) config('mail.from.address'),
            'webhook_url' => $this->webhookUrl(),
        ];
    }

    /**
     * Cambia el remitente. Uno propio queda pendiente y se pide su verificación al proveedor por el outbox.
     */
    public function configure(string $mode, ?string $address): void
    {
        if ($mode === 'own' && ($address === null || filter_var($address, FILTER_VALIDATE_EMAIL) === false)) {
            throw new BusinessRuleViolatedException('invalid_sender', (string) trans('notifications.sender.invalid_address'));
        }

        DB::transaction(function () use ($mode, $address): void {
            $row = $this->row() ?? new MessageChannel(['channel' => 'email', 'provider' => MessageChannel::PLATFORM_EMAIL]);
            $before = ['sender' => $row->sender_identity, 'verification_status' => $row->verification_status ?? 'not_required'];
            if ($mode === 'platform') {
                $row->fill(['sender_identity' => null, 'status' => 'inactive', 'verification_status' => 'not_required', 'verified_at' => null, 'verification_error' => null])->save();
            } else {
                $address = mb_strtolower(trim((string) $address));
                if ($row->sender_identity === $address && $row->verification_status === 'verified') {
                    return;
                }
                $row->fill(['sender_identity' => $address, 'status' => 'inactive', 'verification_status' => 'pending', 'verified_at' => null, 'verification_error' => null])->save();
                $this->requestCheck($row);
            }
            $this->audit->record('message_channel.sender_changed', 'message_channel', $row->id, $before, ['sender' => $row->sender_identity, 'verification_status' => $row->verification_status]);
        });
    }

    /**
     * Vuelve a pedir la verificación del remitente propio (por ejemplo, después de publicar los registros DNS).
     */
    public function verify(): void
    {
        $row = $this->row();
        if ($row === null || $row->sender_identity === null) {
            throw new InvalidTransitionException('not_required', [], (string) trans('notifications.sender.nothing_to_verify'));
        }
        if ($row->verification_status === 'verified') {
            throw new InvalidTransitionException('verified', [], (string) trans('notifications.sender.already_verified'));
        }
        DB::transaction(function () use ($row): void {
            $row->fill(['verification_status' => 'pending', 'verification_error' => null])->save();
            $this->requestCheck($row);
            $this->audit->record('message_channel.sender_verification_requested', 'message_channel', $row->id, null, ['sender' => $row->sender_identity]);
        });
    }

    /**
     * Remitente propio verificado y activo; null = el de la plataforma.
     */
    public function fromAddress(): ?string
    {
        $row = $this->row();

        return $row !== null && $row->sender_identity !== null && $row->status === 'active' && $row->verification_status === 'verified'
            ? $row->sender_identity : null;
    }

    private function row(): ?MessageChannel
    {
        /** @var MessageChannel|null */
        return MessageChannel::query()->where('channel', 'email')->where('provider', MessageChannel::PLATFORM_EMAIL)->first();
    }

    private function requestCheck(MessageChannel $row): void
    {
        $event = $this->outbox->record(DomainEventTypes::EMAIL_SENDER_VERIFICATION_REQUESTED, 'message_channel', $row->id, ['channel_id' => $row->id]);
        $this->outbox->deliverSoon($event);
    }

    /** Dirección a la que el proveedor envía los rebotes de esta organización. */
    private function webhookUrl(): string
    {
        $slug = ($this->tenants->profile(TenancyScope::requireTenant()) ?? ['slug' => ''])['slug'];
        $base = str_replace('{slug}', $slug, (string) config('tenancy.app_url', 'http://{slug}.localhost:3000'));

        return rtrim($base, '/').'/api/v1/webhooks/email';
    }
}
