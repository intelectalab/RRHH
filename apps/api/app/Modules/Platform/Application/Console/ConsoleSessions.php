<?php

namespace App\Modules\Platform\Application\Console;

use App\Modules\Platform\Infrastructure\Models\PlatformSession;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Sesiones de la consola (SAS-1). Mismo esquema que las sesiones de las organizaciones
 * (ADR 0004: token aleatorio en cookie HttpOnly, solo su SHA-256 en base, XSRF de doble envío),
 * pero con cookie, tabla y duración propias: una sesión de organización no abre la consola.
 */
final class ConsoleSessions
{
    /**
     * @return array{session: PlatformSession, token: string}
     */
    public function open(PlatformUser $user, Request $request): array
    {
        $token = Str::random(64);

        $session = PlatformSession::create([
            'platform_user_id' => $user->id,
            'session_hash' => self::hash($token),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'mfa_passed' => false,
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(max(15, (int) config('platform.session_lifetime_minutes'))),
        ]);

        return ['session' => $session, 'token' => $token];
    }

    public function resolve(Request $request): ?PlatformSession
    {
        $token = $request->cookie((string) config('platform.session_cookie'));
        if (! is_string($token) || $token === '') {
            return null;
        }

        /** @var PlatformSession|null $session */
        $session = PlatformSession::query()->with('user')->where('session_hash', self::hash($token))->first();
        if ($session === null || ! $session->isActive()) {
            return null;
        }

        $idle = max(5, (int) config('platform.idle_minutes'));
        if ($session->last_seen_at !== null && $session->last_seen_at->lt(now()->subMinutes($idle))) {
            $this->revoke($session);

            return null;
        }
        if ($session->last_seen_at === null || $session->last_seen_at->lt(now()->subMinute())) {
            $session->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $session;
    }

    public function revoke(PlatformSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }
    }

    public function revokeAllFor(PlatformUser $user): void
    {
        PlatformSession::query()->where('platform_user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function xsrfFor(string $sessionToken): string
    {
        return hash_hmac('sha256', 'console-xsrf|'.$sessionToken, (string) config('app.key'));
    }

    /**
     * @return array<int, Cookie>
     */
    public function cookies(string $token, PlatformSession $session): array
    {
        $expires = $session->expires_at;
        $secure = (bool) config('identity.secure_cookies');

        return [
            new Cookie((string) config('platform.session_cookie'), $token, $expires, '/', null, $secure, true, false, Cookie::SAMESITE_STRICT),
            new Cookie((string) config('platform.xsrf_cookie'), self::xsrfFor($token), $expires, '/', null, $secure, false, false, Cookie::SAMESITE_STRICT),
        ];
    }

    /**
     * @return array<int, Cookie>
     */
    public function forgetCookies(): array
    {
        $secure = (bool) config('identity.secure_cookies');

        return [
            new Cookie((string) config('platform.session_cookie'), '', 1, '/', null, $secure, true, false, Cookie::SAMESITE_STRICT),
            new Cookie((string) config('platform.xsrf_cookie'), '', 1, '/', null, $secure, false, false, Cookie::SAMESITE_STRICT),
        ];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
