<?php

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\OneTimeTokens;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Identity\Infrastructure\Models\UserToken;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\BlindIndex;
use Illuminate\Support\Facades\DB;

/**
 * SEG-1 · Solicitud del enlace de recuperación de clave.
 *
 * Responde igual exista o no el correo. Si existe, invalida los enlaces previos,
 * crea un token de un solo uso (solo su hash en base) y deja el envío en el
 * outbox, en la misma transacción, con el token cifrado.
 */
final class RequestPasswordReset
{
    public function __construct(private readonly OneTimeTokens $tokens) {}

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

        $this->issueFor($user, $origin);
    }

    public function issueFor(User $user, string $origin): void
    {
        DB::transaction(fn () => $this->tokens->issue(
            $user,
            UserToken::PURPOSE_PASSWORD_RESET,
            (int) config('identity.password_reset_ttl_minutes'),
            DomainEventTypes::USER_PASSWORD_RESET_REQUESTED,
            $origin,
        ));
    }
}
