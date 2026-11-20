<?php

namespace App\Modules\Identity\Infrastructure\Security;

use App\Modules\Identity\Application\MessageCodes;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Messaging\MessageSender;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\StepUpCodes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * DOC-7 · Código de firma (purpose signature_otp): por correo si el usuario lo tiene; si no, por SMS a su teléfono
 * con la capa de mensajería (NOT-5). Mismo almacenamiento, caducidad e intentos que los códigos de ingreso (SEG-2).
 */
final class MessageStepUpCodes implements StepUpCodes
{
    public function __construct(
        private readonly MessageCodes $codes,
        private readonly OutboxWriter $outbox,
        private readonly MessageSender $messages,
    ) {}

    public function send(string $userId, string $purpose): ?array
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);
        if ($user === null) {
            return null;
        }
        $email = UserIdentity::query()->where('user_id', $user->id)->where('kind', 'email')->exists();
        /** @var UserIdentity|null $phone */
        $phone = $email ? null : UserIdentity::query()->where('user_id', $user->id)->where('kind', 'phone')->first();
        if (! $email && $phone === null) {
            return null;
        }
        $channel = $email ? 'email' : 'sms';

        return DB::transaction(function () use ($user, $purpose, $channel, $phone): array {
            $issued = $this->codes->issue($user, $purpose, $channel, 6);
            $minutes = $this->codes->ttlMinutes();
            if ($channel === 'email') {
                $event = $this->outbox->record(DomainEventTypes::USER_MESSAGE_CODE_ISSUED, 'user', $user->id, [
                    'user_id' => $user->id, 'purpose' => $purpose, 'channel' => 'email',
                    'code_enc' => Crypt::encryptString($issued['code']), 'minutes' => $minutes,
                ]);
                $this->outbox->deliverSoon($event);
            } elseif ($phone !== null) {
                $this->messages->send('sms', '+'.$phone->identifier(), 'identity.login_code', ['code' => $issued['code'], 'minutes' => $minutes],
                    $user->locale ?? (string) config('app.locale', 'es'), $purpose, 'message_code:'.$issued['id']);
            }

            return ['channel' => $channel, 'reference' => $issued['id'], 'expires_at' => $issued['expires_at']->toIso8601String()];
        });
    }

    public function verify(string $userId, string $purpose, string $code): bool
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        return $user !== null && $this->codes->consume($user, $purpose, $code);
    }
}
