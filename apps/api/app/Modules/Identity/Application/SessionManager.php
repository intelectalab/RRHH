<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Emite, resuelve y revoca sesiones web (ADR 0004).
 *
 * - La cookie lleva un token aleatorio; en base solo se guarda su SHA-256.
 * - La búsqueda ocurre bajo RLS: una cookie emitida en otra organización no se
 *   encuentra en este host y la petición queda sin autenticar.
 * - El token XSRF se deriva del token de sesión (doble envío): el cliente lo lee
 *   de la cookie XSRF-TOKEN y lo devuelve en la cabecera X-XSRF-TOKEN.
 */
final class SessionManager
{
    /**
     * @return array{session: UserSession, token: string}
     */
    public function open(User $user, Request $request): array
    {
        $token = Str::random(64);

        $session = UserSession::create([
            'user_id' => $user->id,
            'session_hash' => self::hash($token),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes((int) config('identity.session_lifetime_minutes')),
        ]);

        return ['session' => $session, 'token' => $token];
    }

    public function resolve(Request $request): ?UserSession
    {
        $token = $request->cookie((string) config('identity.session_cookie'));
        if (! is_string($token) || $token === '') {
            return null;
        }

        /** @var UserSession|null $session */
        $session = UserSession::query()->with('user')->where('session_hash', self::hash($token))->first();

        return $session !== null && $session->isActive() ? $session : null;
    }

    public function revoke(UserSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAllFor(User $user, ?string $exceptSessionId = null): void
    {
        UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptSessionId !== null, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->update(['revoked_at' => now()]);
    }

    public static function xsrfFor(string $sessionToken): string
    {
        return hash_hmac('sha256', 'xsrf|'.$sessionToken, (string) config('app.key'));
    }

    /**
     * @return array<int, Cookie>
     */
    public function cookies(string $token, UserSession $session): array
    {
        $minutes = (int) max(1, now()->diffInMinutes($session->expires_at));
        $secure = (bool) config('identity.secure_cookies');

        return [
            new Cookie((string) config('identity.session_cookie'), $token, now()->addMinutes($minutes), '/', null, $secure, true, false, Cookie::SAMESITE_LAX),
            new Cookie((string) config('identity.xsrf_cookie'), self::xsrfFor($token), now()->addMinutes($minutes), '/', null, $secure, false, false, Cookie::SAMESITE_LAX),
        ];
    }

    /**
     * @return array<int, Cookie>
     */
    public function forgetCookies(): array
    {
        return [
            new Cookie((string) config('identity.session_cookie'), '', 1, '/', null, (bool) config('identity.secure_cookies'), true, false, Cookie::SAMESITE_LAX),
            new Cookie((string) config('identity.xsrf_cookie'), '', 1, '/', null, (bool) config('identity.secure_cookies'), false, false, Cookie::SAMESITE_LAX),
        ];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
