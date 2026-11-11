<?php

namespace App\Modules\Platform\Application\Console;

use App\Modules\Platform\Infrastructure\Models\PlatformSession;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Security\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SAS-1 · Ingreso a la consola: clave y luego segundo factor, SIEMPRE obligatorio (ADR 0011).
 *
 * - La clave sola nunca opera: abre una sesión con el segundo paso pendiente (enroll o verify).
 * - Un mismo código TOTP no sirve dos veces (se recuerda el último paso usado).
 * - Los fallos de clave y de código cuentan para el bloqueo temporal.
 * - Toda acción queda en la auditoría propia de la consola, sin claves ni códigos.
 */
final class ConsoleAuth
{
    public function __construct(
        private readonly ConsoleSessions $sessions,
        private readonly ConsoleAudit $audit,
    ) {}

    /**
     * Devuelve el usuario si la clave es correcta y la cuenta puede ingresar; null en otro caso
     * (sin revelar si el correo existe).
     */
    public function attempt(string $email, string $password): ?PlatformUser
    {
        /** @var PlatformUser|null $user */
        $user = PlatformUser::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();

        if ($user === null) {
            // Mismo costo que una cuenta real para no revelar qué correos existen.
            Hash::make($password);
            $this->audit->record('console.login_failed', null, null, null, null, ['reason' => 'unknown_account']);

            return null;
        }
        if (! $user->canSignIn()) {
            $this->audit->record('console.login_failed', $user, 'platform_user', $user->id, null, ['reason' => $user->isLocked() ? 'locked' : 'blocked']);

            return null;
        }
        if ($user->password_hash === null || ! Hash::check($password, $user->password_hash)) {
            $this->registerFailure($user, 'password');

            return null;
        }

        return $user;
    }

    /** Paso pendiente de una sesión: ok, verify (pedir el código) o enroll (configurarlo). */
    public function step(PlatformUser $user, PlatformSession $session): string
    {
        if ($session->mfa_passed) {
            return 'ok';
        }

        return $user->hasMfa() ? 'verify' : 'enroll';
    }

    /**
     * @return array{secret: string, otpauth_uri: string}
     */
    public function startEnrollment(PlatformUser $user): array
    {
        if ($user->hasMfa()) {
            throw new BusinessRuleViolatedException('mfa_already_enabled', (string) trans('platform.mfa_already_enabled'));
        }
        $secret = Totp::newSecret();
        DB::transaction(function () use ($user, $secret): void {
            $user->forceFill(['mfa_secret_enc' => Crypt::encryptString($secret), 'mfa_confirmed_at' => null, 'mfa_last_used_step' => null])->save();
            $this->audit->record('console.mfa_enrollment_started', $user, 'platform_user', $user->id);
        });

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::uri($secret, (string) config('platform.mfa_issuer'), $user->email),
        ];
    }

    public function confirmEnrollment(PlatformUser $user, PlatformSession $session, string $code): void
    {
        if ($user->hasMfa()) {
            throw new BusinessRuleViolatedException('mfa_already_enabled', (string) trans('platform.mfa_already_enabled'));
        }
        if ($user->mfa_secret_enc === null) {
            throw new BusinessRuleViolatedException('mfa_enrollment_not_started', (string) trans('platform.mfa_enrollment_not_started'));
        }
        $secret = Crypt::decryptString($user->mfa_secret_enc);
        $step = Totp::match($secret, $code);
        if ($step === null) {
            $drift = Totp::drift($secret, $code);
            $this->registerFailure($user, 'mfa_enroll');
            throw ValidationException::withMessages(['code' => [$drift === null
                ? (string) trans('platform.invalid_code')
                : (string) trans('platform.clock_skew', ['minutes' => max(1, (int) round(abs($drift) / 60))])]]);
        }

        DB::transaction(function () use ($user, $session, $step): void {
            $user->forceFill(['mfa_confirmed_at' => now(), 'mfa_last_used_step' => $step])->save();
            $this->passed($user, $session);
            $this->audit->record('console.mfa_enabled', $user, 'platform_user', $user->id, ['mfa_enabled' => false], ['mfa_enabled' => true]);
        });
    }

    public function verify(PlatformUser $user, PlatformSession $session, string $code): void
    {
        if (! $user->hasMfa()) {
            throw new BusinessRuleViolatedException('mfa_not_enabled', (string) trans('platform.mfa_not_enabled'));
        }
        $step = Totp::match(Crypt::decryptString((string) $user->mfa_secret_enc), $code);
        if ($step === null || ($user->mfa_last_used_step !== null && $step <= $user->mfa_last_used_step)) {
            $this->registerFailure($user, 'mfa');
            throw ValidationException::withMessages(['code' => [(string) trans('platform.invalid_code')]]);
        }

        DB::transaction(function () use ($user, $session, $step): void {
            $user->forceFill(['mfa_last_used_step' => $step])->save();
            $this->passed($user, $session);
        });
    }

    public function logout(PlatformUser $user, PlatformSession $session): void
    {
        $this->sessions->revoke($session);
        $this->audit->record('console.logout', $user, 'platform_session', $session->id);
    }

    /**
     * Restablece el segundo factor (solo desde la terminal del servidor): el próximo ingreso
     * vuelve a configurarlo y se cierran las sesiones abiertas.
     */
    public function resetMfa(PlatformUser $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill(['mfa_secret_enc' => null, 'mfa_confirmed_at' => null, 'mfa_last_used_step' => null])->save();
            $this->sessions->revokeAllFor($user);
            $this->audit->record('console.mfa_reset', null, 'platform_user', $user->id, ['mfa_enabled' => true], ['mfa_enabled' => false]);
        });
    }

    private function passed(PlatformUser $user, PlatformSession $session): void
    {
        $session->forceFill(['mfa_passed' => true])->save();
        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        $this->audit->record('console.login', $user, 'platform_session', $session->id, null, ['mfa' => 'totp']);
    }

    private function registerFailure(PlatformUser $user, string $reason): void
    {
        $attempts = $user->failed_attempts + 1;
        $max = max(1, (int) config('platform.max_failed_attempts'));
        $locked = $attempts >= $max;
        $user->forceFill([
            'failed_attempts' => $locked ? 0 : $attempts,
            'locked_until' => $locked ? now()->addMinutes(max(1, (int) config('platform.lockout_minutes'))) : $user->locked_until,
        ])->save();
        $this->audit->record('console.login_failed', $user, 'platform_user', $user->id, null, ['reason' => $reason, 'attempts' => $attempts]);
        if ($locked) {
            $this->sessions->revokeAllFor($user);
            $this->audit->record('console.account_locked', $user, 'platform_user', $user->id, null, ['minutes' => (int) config('platform.lockout_minutes')]);
        }
    }
}
