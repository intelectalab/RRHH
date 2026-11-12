<?php

namespace App\Modules\Notifications\Application\Email;

use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Integrations\Email\EmailFeedbackEvent;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\BlindIndex;
use App\Shared\Security\FieldCipher;
use Illuminate\Support\Facades\DB;

/**
 * NOT-3 · Avisos del proveedor de correo sobre lo enviado. Un rebote permanente o una queja marcan la
 * dirección como inválida (no se vuelve a usar: RN-NOT-07 lleva los avisos por SMS/WhatsApp) y se avisa
 * a RRHH. Reprocesar el mismo aviso no repite nada.
 */
final class EmailFeedback
{
    public function __construct(
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<EmailFeedbackEvent>  $events
     * @return int avisos que cambiaron algo
     */
    public function apply(array $events): int
    {
        $changed = 0;
        foreach ($events as $event) {
            $changed += DB::transaction(fn (): int => $this->applyOne($event));
        }

        return $changed;
    }

    /**
     * RRHH corrigió la dirección o confirmó que ya funciona: vuelve a usarse.
     */
    public function clearBounce(NotificationDelivery $delivery): void
    {
        $hash = $this->addressHash($delivery);
        $bounced = $hash === null ? 0 : DB::table('user_identities')->where('kind', 'email')->where('identifier_hash', $hash)->whereNotNull('bounced_at')->count();
        if ($delivery->channel !== 'email' || $delivery->status !== 'bounced' || $bounced === 0) {
            throw new InvalidTransitionException($delivery->status, [], (string) trans('notifications.bounce.nothing_to_clear'));
        }
        DB::transaction(function () use ($hash): void {
            $users = DB::table('user_identities')->where('kind', 'email')->where('identifier_hash', $hash)->whereNotNull('bounced_at')->pluck('user_id');
            DB::table('user_identities')->where('kind', 'email')->where('identifier_hash', $hash)->update(['bounced_at' => null, 'bounce_reason' => null]);
            foreach ($users as $userId) {
                $this->audit->record('user_identity.email_bounce_cleared', 'user', (string) $userId, ['email_bounced' => true], ['email_bounced' => false], null);
            }
        });
    }

    private function applyOne(EmailFeedbackEvent $event): int
    {
        /** @var NotificationDelivery|null $delivery */
        $delivery = NotificationDelivery::query()->where('channel', 'email')->where('provider_message_id', $event->providerMessageId)->lockForUpdate()->first();
        if ($delivery === null) {
            return 0;
        }

        if ($event->kind === 'delivered') {
            if ($delivery->status !== 'sent') {
                return 0;
            }
            $delivery->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();

            return 1;
        }

        if ($event->kind === 'soft_bounce') {
            $delivery->forceFill(['error' => $this->reason($event)])->save();

            return 1;
        }

        // bounce o complaint
        if ($delivery->status === 'bounced') {
            return 0;
        }
        $delivery->forceFill(['status' => 'bounced', 'error' => $this->reason($event)])->save();

        $hash = $this->addressHash($delivery);
        $users = $hash === null ? collect() : DB::table('user_identities')->where('kind', 'email')->where('identifier_hash', $hash)->whereNull('bounced_at')->pluck('user_id');
        if ($hash !== null) {
            DB::table('user_identities')->where('kind', 'email')->where('identifier_hash', $hash)->whereNull('bounced_at')
                ->update(['bounced_at' => now(), 'bounce_reason' => $event->kind]);
        }
        foreach ($users as $userId) {
            // La dirección nunca va a la auditoría ni al evento: solo el usuario y el motivo.
            $this->audit->record('user_identity.email_bounced', 'user', (string) $userId, ['email_bounced' => false], ['email_bounced' => true, 'kind' => $event->kind], null);
            $this->outbox->record(DomainEventTypes::EMAIL_ADDRESS_BOUNCED, 'user', (string) $userId, [
                'user_id' => (string) $userId, 'delivery_id' => $delivery->id, 'kind' => $event->kind,
            ]);
        }

        return 1;
    }

    private function addressHash(NotificationDelivery $delivery): ?string
    {
        return $delivery->recipient_address_enc === null ? null : BlindIndex::for('email', FieldCipher::decrypt($delivery->recipient_address_enc));
    }

    private function reason(EmailFeedbackEvent $event): string
    {
        return mb_substr(trim($event->kind.($event->reason !== null ? ': '.$event->reason : '')), 0, 500);
    }
}
