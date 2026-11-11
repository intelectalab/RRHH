<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SEG-2 · Códigos numéricos de un solo uso para quien no tiene correo: ingreso (otp_login) y
 * activación de la cuenta (invite). Caducan, sirven una vez y tienen tope de intentos.
 *
 * En base solo queda un HMAC que incluye al usuario: un código corto no se puede revertir
 * ni coincide con el de otro usuario.
 */
final class MessageCodes
{
    public function __construct(private readonly ParameterResolver $parameters) {}

    public function ttlMinutes(): int
    {
        return max(2, (int) ($this->parameters->value('seguridad.otp_minutos') ?? 5));
    }

    public function maxAttempts(): int
    {
        return max(3, (int) ($this->parameters->value('seguridad.otp_intentos') ?? 5));
    }

    /** ¿Se envió un código hace muy poco? Evita abusar del envío de mensajes. */
    public function recentlySent(User $user, string $purpose): bool
    {
        $wait = max(30, (int) ($this->parameters->value('seguridad.otp_reenvio_segundos') ?? 60));

        return DB::table('user_tokens')->where('user_id', $user->id)->where('purpose', $purpose)
            ->where('created_at', '>', now()->subSeconds($wait))->exists();
    }

    /**
     * Emite un código nuevo (anula los anteriores del mismo propósito). Debe llamarse dentro de una transacción.
     *
     * @return array{id: string, code: string, expires_at: Carbon}
     */
    public function issue(User $user, string $purpose, string $channel, int $digits, ?int $ttlMinutes = null): array
    {
        DB::table('user_tokens')->where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = '';
        for ($i = 0; $i < $digits; $i++) {
            $code .= (string) random_int(0, 9);
        }
        $expires = now()->addMinutes($ttlMinutes ?? $this->ttlMinutes());
        $id = (string) DB::table('user_tokens')->insertGetId([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => self::hash($user->id, $code),
            'channel' => $channel,
            'expires_at' => $expires,
        ]);

        return ['id' => $id, 'code' => $code, 'expires_at' => $expires];
    }

    /**
     * Verifica y consume. Cada fallo suma un intento al código vigente; al llegar al tope se anula.
     */
    public function consume(User $user, string $purpose, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        return DB::transaction(function () use ($user, $purpose, $code): bool {
            $token = DB::table('user_tokens')->where('user_id', $user->id)->where('purpose', $purpose)
                ->whereNull('consumed_at')->where('expires_at', '>', now())
                // Las invitaciones por correo son enlaces largos (OneTimeTokens), no códigos.
                ->when($purpose === 'invite', fn ($q) => $q->where('channel', '!=', 'email'))
                ->orderByDesc('created_at')->lockForUpdate()->first();
            if ($token === null) {
                return false;
            }
            if ($code !== '' && hash_equals((string) $token->token_hash, self::hash($user->id, $code))) {
                DB::table('user_tokens')->where('id', $token->id)->update(['consumed_at' => now()]);

                return true;
            }
            $attempts = (int) $token->attempts + 1;
            DB::table('user_tokens')->where('id', $token->id)->update([
                'attempts' => $attempts,
                'consumed_at' => $attempts >= $this->maxAttempts() ? now() : null,
            ]);

            return false;
        });
    }

    private static function hash(string $userId, string $code): string
    {
        return hash_hmac('sha256', 'message-code|'.$userId.'|'.$code, (string) config('app.key'));
    }
}
