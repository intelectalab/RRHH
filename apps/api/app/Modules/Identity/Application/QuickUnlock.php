<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * ESS-6 · Reingreso rápido en el teléfono con PIN.
 *
 * - Se activa desde una sesión ya abierta con clave o código: el teléfono recibe un token de
 *   dispositivo en una cookie HttpOnly (en base solo su SHA-256) y el PIN se guarda con hash.
 * - Reingresar exige las dos cosas (el teléfono y el PIN). La sesión nueva conserva el segundo factor
 *   solo si la sesión que activó el PIN lo había superado.
 * - Caduca según seguridad.pin_dias_vigencia y se olvida tras seguridad.pin_intentos PIN errados.
 */
final class QuickUnlock
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
    ) {}

    public function days(): int
    {
        return max(0, min(180, (int) ($this->parameters->value('seguridad.pin_dias_vigencia') ?? 30)));
    }

    public function maxAttempts(): int
    {
        return max(3, min(10, (int) ($this->parameters->value('seguridad.pin_intentos') ?? 5)));
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public function enroll(User $user, ?UserSession $session, string $pin, Request $request, ?string $currentToken): array
    {
        $days = $this->days();
        if ($days === 0) {
            throw new AyniException('business_rule_violated', 422, (string) trans('identity.quick_unlock.disabled'), [], ['rule' => 'ESS-6']);
        }
        self::guardPin($pin);
        $token = Str::random(64);
        $expires = now()->addDays($days);

        DB::transaction(function () use ($user, $session, $pin, $request, $currentToken, $token, $expires): void {
            if ($currentToken !== null) {
                // Cambiar el PIN en el mismo teléfono reemplaza el anterior.
                DB::table('user_quick_unlocks')->where('device_token_hash', self::hash($currentToken))->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            $id = (string) DB::table('user_quick_unlocks')->insertGetId([
                'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $user->id, 'device_token_hash' => self::hash($token),
                'pin_hash' => Hash::make($pin), 'device_label' => SessionManager::deviceLabel((string) $request->userAgent()),
                'mfa_passed' => (bool) ($session?->mfa_passed), 'expires_at' => $expires,
            ]);
            $this->audit->record('auth.quick_unlock_enabled', 'user', $user->id, null, ['quick_unlock_id' => $id, 'expires_at' => $expires->toIso8601String()]);
        });

        return ['token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    /**
     * Estado del reingreso rápido de este teléfono (sin revelar nada si no hay uno vigente).
     *
     * @return array{available: bool, display_name: string|null}
     */
    public function status(?string $token): array
    {
        $row = $this->active($token);
        if ($row === null) {
            return ['available' => false, 'display_name' => null];
        }
        $name = DB::table('users')->where('id', $row->user_id)->value('display_name');

        return ['available' => true, 'display_name' => is_string($name) ? $name : null];
    }

    /**
     * @return array{user: User, session: UserSession, token: string}|null null si el PIN no coincide
     */
    public function signIn(?string $token, string $pin, Request $request): ?array
    {
        $row = $this->active($token);
        if ($row === null) {
            throw new AyniException('invalid_credentials', 401, (string) trans('identity.quick_unlock.unavailable'));
        }
        /** @var User|null $user */
        $user = User::query()->whereKey($row->user_id)->first();
        if ($user === null || $user->status !== User::STATUS_ACTIVE) {
            $this->revoke((string) $row->id);

            throw new AyniException('invalid_credentials', 401, (string) trans('identity.quick_unlock.unavailable'));
        }
        if (! Hash::check($pin, (string) $row->pin_hash)) {
            $failed = (int) $row->failed_attempts + 1;
            DB::table('user_quick_unlocks')->where('id', $row->id)->update(['failed_attempts' => $failed, 'updated_at' => now()]);
            if ($failed >= $this->maxAttempts()) {
                $this->revoke((string) $row->id);
                $this->audit->record('auth.quick_unlock_forgotten', 'user', $user->id, null, ['reason' => 'too_many_attempts']);
            }

            return null;
        }

        return DB::transaction(function () use ($row, $user, $request): array {
            DB::table('user_quick_unlocks')->where('id', $row->id)->update(['failed_attempts' => 0, 'last_used_at' => now(), 'updated_at' => now()]);
            ['session' => $session, 'token' => $sessionToken] = $this->sessions->open($user, $request);
            if ((bool) $row->mfa_passed) {
                $session->forceFill(['mfa_passed' => true])->save();
            }
            $user->forceFill(['last_login_at' => now()])->save();
            $this->audit->record('auth.quick_unlock_used', 'user', $user->id, null, ['session_id' => $session->id]);

            return ['user' => $user, 'session' => $session, 'token' => $sessionToken];
        });
    }

    public function forget(?string $token): void
    {
        $row = $this->active($token);
        if ($row !== null) {
            $this->revoke((string) $row->id);
        }
    }

    public function cookie(string $token, int $days): Cookie
    {
        return new Cookie(self::cookieName(), $token, now()->addDays($days), '/', null, (bool) config('identity.secure_cookies'), true, false, Cookie::SAMESITE_LAX);
    }

    public function forgetCookie(): Cookie
    {
        return new Cookie(self::cookieName(), '', 1, '/', null, (bool) config('identity.secure_cookies'), true, false, Cookie::SAMESITE_LAX);
    }

    public static function cookieName(): string
    {
        return (string) config('identity.device_cookie', 'gestia_device');
    }

    /**
     * PIN de 4 a 8 dígitos que no sea un solo dígito repetido ni una escalera.
     */
    public static function guardPin(string $pin): void
    {
        $trivial = preg_match('/^(\d)\1+$/', $pin) === 1 || str_contains('0123456789', $pin) || str_contains('9876543210', $pin);
        if (preg_match('/^\d{4,8}$/', $pin) !== 1 || $trivial) {
            throw ValidationException::withMessages(['pin' => [(string) trans('identity.quick_unlock.weak_pin')]]);
        }
    }

    private function active(?string $token): ?\stdClass
    {
        if ($token === null || $token === '') {
            return null;
        }
        $row = DB::table('user_quick_unlocks')->where('device_token_hash', self::hash($token))->whereNull('revoked_at')
            ->where('expires_at', '>', now())->first();

        return $row instanceof \stdClass ? $row : null;
    }

    private function revoke(string $id): void
    {
        DB::table('user_quick_unlocks')->where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
