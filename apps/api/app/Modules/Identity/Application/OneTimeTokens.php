<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserToken;
use App\Shared\Outbox\Contracts\OutboxWriter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enlaces de un solo uso (invitación, recuperación de clave).
 * En base solo queda el hash; el envío sale por el outbox con el token cifrado.
 */
final class OneTimeTokens
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    /**
     * Emite un enlace nuevo e invalida los anteriores del mismo propósito.
     * Debe llamarse dentro de una transacción.
     */
    public function issue(User $user, string $purpose, int $ttlMinutes, string $eventType, string $origin): UserToken
    {
        UserToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $raw = Str::random(64);
        $token = UserToken::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $raw),
            'channel' => 'email',
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        $this->outbox->record($eventType, 'user', $user->id, [
            'user_id' => $user->id,
            'user_token_id' => $token->id,
            'token_enc' => Crypt::encryptString($raw),
            'expires_at' => $token->expires_at->toIso8601String(),
            'origin' => $origin,
        ]);

        return $token;
    }

    /**
     * Consume un enlace vigente (bloqueo de fila). Debe llamarse dentro de una transacción.
     */
    public function consume(string $raw, string $purpose): ?UserToken
    {
        /** @var UserToken|null $token */
        $token = UserToken::query()
            ->where('purpose', $purpose)
            ->where('token_hash', hash('sha256', $raw))
            ->lockForUpdate()
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        $token->forceFill(['consumed_at' => now()])->save();

        return $token;
    }

    public function revokeAll(User $user, string $purpose): void
    {
        DB::table('user_tokens')
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }
}
