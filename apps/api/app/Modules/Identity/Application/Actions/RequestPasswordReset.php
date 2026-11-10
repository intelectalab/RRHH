<?php

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Identity\Infrastructure\Models\UserToken;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\BlindIndex;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEG-1 · Solicitud del enlace de recuperación de clave.
 *
 * Responde igual exista o no el correo. Si existe, invalida los enlaces previos,
 * crea un token de un solo uso (solo su hash en base) y deja el envío en el
 * outbox, en la misma transacción, con el token cifrado.
 */
final class RequestPasswordReset
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function __invoke(string $email, string $origin): void
    {
        /** @var UserIdentity|null $identity */
        $identity = UserIdentity::query()
            ->with('user')
            ->where('kind', 'email')
            ->where('identifier_hash', BlindIndex::for('email', $email))
            ->first();

        $user = $identity?->user;
        if ($user === null || ! in_array($user->status, ['active', 'locked'], true)) {
            return;
        }

        DB::transaction(function () use ($user, $origin): void {
            UserToken::query()
                ->where('user_id', $user->id)
                ->where('purpose', UserToken::PURPOSE_PASSWORD_RESET)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $raw = Str::random(64);
            $token = UserToken::create([
                'user_id' => $user->id,
                'purpose' => UserToken::PURPOSE_PASSWORD_RESET,
                'token_hash' => hash('sha256', $raw),
                'channel' => 'email',
                'expires_at' => now()->addMinutes((int) config('identity.password_reset_ttl_minutes')),
            ]);

            $this->outbox->record(
                DomainEventTypes::USER_PASSWORD_RESET_REQUESTED,
                'user',
                $user->id,
                [
                    'user_id' => $user->id,
                    'user_token_id' => $token->id,
                    'token_enc' => Crypt::encryptString($raw),
                    'expires_at' => $token->expires_at->toIso8601String(),
                    'origin' => $origin,
                ],
            );
        });
    }
}
