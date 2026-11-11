<?php

namespace App\Modules\Identity\Application\Mfa;

use App\Modules\Identity\Application\AccountLockout;
use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Security\Totp;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SEG-3 · Segundo factor con aplicación autenticadora (TOTP) y códigos de respaldo.
 *
 * - El secreto se guarda cifrado con la clave de la aplicación; los códigos de respaldo,
 *   como HMAC: el texto solo se muestra una vez al generarlos.
 * - RN-SEG-08: un rol puede exigirlo (roles.mfa_required, o el valor por defecto del rol de
 *   sistema); el parámetro seguridad.mfa_todos lo exige a toda la organización (ADR 0009).
 * - Un mismo código TOTP no sirve dos veces (se recuerda el último paso usado).
 */
final class MfaService
{
    public const RECOVERY_CODES = 10;

    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly AccountLockout $lockout,
        private readonly SessionManager $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function hasActiveFactor(User $user): bool
    {
        return DB::table('user_mfa_factors')->where('user_id', $user->id)->where('kind', 'totp')
            ->whereNotNull('confirmed_at')->whereNull('disabled_at')->exists();
    }

    public function isRequired(User $user): bool
    {
        if ($this->parameters->value('seguridad.mfa_todos') === true) {
            return true;
        }

        return DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->leftJoin('system_roles as sr', 'sr.code', '=', 'r.system_role_code')
            ->where('ur.user_id', $user->id)
            ->whereNull('r.deleted_at')
            ->whereRaw('COALESCE(r.mfa_required, sr.mfa_required_default, false)')
            ->exists();
    }

    /**
     * Estado del segundo paso para la sesión: ok (puede operar), verify (debe ingresar el código)
     * o enroll (su rol lo exige y aún no lo configuró).
     */
    public function sessionStatus(User $user, ?UserSession $session): string
    {
        if ($session === null || $session->mfa_passed) {
            return 'ok';
        }
        if ($this->hasActiveFactor($user)) {
            return 'verify';
        }

        return $this->isRequired($user) ? 'enroll' : 'ok';
    }

    /**
     * @return array{enabled: bool, required: bool, confirmed_at: string|null, recovery_codes_left: int}
     */
    public function summary(User $user): array
    {
        $factor = $this->activeFactor($user);

        return [
            'enabled' => $factor !== null,
            'required' => $this->isRequired($user),
            'confirmed_at' => $factor === null ? null : Carbon::parse((string) $factor->confirmed_at)->toIso8601String(),
            'recovery_codes_left' => $factor === null ? 0 : DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count(),
        ];
    }

    /**
     * Inicia la configuración: un secreto nuevo pendiente de confirmar (reemplaza a uno pendiente anterior).
     *
     * @return array{secret: string, otpauth_uri: string}
     */
    public function startEnrollment(User $user, string $account): array
    {
        if ($this->hasActiveFactor($user)) {
            throw new BusinessRuleViolatedException('mfa_already_enabled', (string) trans('identity.mfa.mfa_already_enabled'));
        }
        // Si ya hay una configuración reciente sin confirmar, se reutiliza su secreto: abrir la pantalla
        // dos veces (o dos peticiones simultáneas) no debe invalidar el QR que el usuario ya escaneó.
        $recent = DB::table('user_mfa_factors')->where('user_id', $user->id)->where('kind', 'totp')
            ->whereNull('confirmed_at')->whereNull('disabled_at')->where('created_at', '>=', now()->subMinutes(30))
            ->orderByDesc('created_at')->first();
        if ($recent !== null) {
            $secret = Crypt::decryptString((string) $recent->secret_enc);

            return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, (string) config('app.name'), $account)];
        }
        $secret = Totp::newSecret();
        DB::transaction(function () use ($user, $secret): void {
            DB::table('user_mfa_factors')->where('user_id', $user->id)->where('kind', 'totp')->whereNull('confirmed_at')->delete();
            DB::table('user_mfa_factors')->insert([
                'tenant_id' => TenancyScope::requireTenant(),
                'user_id' => $user->id,
                'kind' => 'totp',
                'secret_enc' => Crypt::encryptString($secret),
                'label' => (string) trans('identity.mfa.authenticator_label'),
            ]);
        });

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($secret, (string) config('app.name'), $account)];
    }

    /**
     * Confirma con un código de la aplicación, activa el factor y entrega los códigos de respaldo (una sola vez).
     *
     * @return list<string>
     */
    public function confirmEnrollment(User $user, string $code, ?UserSession $session): array
    {
        $pending = DB::table('user_mfa_factors')->where('user_id', $user->id)->where('kind', 'totp')
            ->whereNull('confirmed_at')->whereNull('disabled_at')->orderByDesc('created_at')->first();
        if ($pending === null) {
            throw new BusinessRuleViolatedException('mfa_enrollment_not_started', (string) trans('identity.mfa.mfa_enrollment_not_started'));
        }
        $secret = Crypt::decryptString((string) $pending->secret_enc);
        $step = Totp::match($secret, $code);
        if ($step === null) {
            $this->lockout->activity($user, 'auth.mfa_failed');
            // Diagnóstico al configurar: si el código corresponde a otra hora, el reloj del teléfono
            // o del servidor está desfasado. No se acepta; solo se explica.
            $drift = Totp::drift($secret, $code);
            throw ValidationException::withMessages(['code' => [$drift === null
                ? (string) trans('identity.mfa.invalid_code')
                : (string) trans('identity.mfa.clock_skew', ['minutes' => max(1, (int) round(abs($drift) / 60))])]]);
        }

        return DB::transaction(function () use ($user, $pending, $step, $session): array {
            DB::table('user_mfa_factors')->where('id', $pending->id)->update(['confirmed_at' => now(), 'last_used_at' => $this->stepTime($step)]);
            $codes = $this->replaceRecoveryCodes($user);
            if ($session !== null) {
                $session->forceFill(['mfa_passed' => true])->save();
            }
            $this->audit->record('user.mfa_enabled', 'user', $user->id, ['mfa_enabled' => false], ['mfa_enabled' => true]);
            $this->lockout->activity($user, 'auth.mfa_enabled');

            return $codes;
        });
    }

    /**
     * Segundo paso del ingreso: código de la aplicación o código de respaldo (que queda usado).
     * Los fallos cuentan para el bloqueo igual que la clave (RN-SEG-07).
     */
    public function verify(User $user, UserSession $session, string $code): bool
    {
        $factor = $this->activeFactor($user);
        if ($factor === null) {
            return false;
        }
        $ok = DB::transaction(function () use ($user, $factor, $code): bool {
            $step = Totp::match(Crypt::decryptString((string) $factor->secret_enc), $code);
            $lastStep = $factor->last_used_at === null ? null : Totp::currentStep(Carbon::parse((string) $factor->last_used_at)->getTimestamp());
            if ($step !== null && ($lastStep === null || $step > $lastStep)) {
                DB::table('user_mfa_factors')->where('id', $factor->id)->update(['last_used_at' => $this->stepTime($step)]);

                return true;
            }

            return $this->useRecoveryCode($user, $code);
        });

        if (! $ok) {
            if ($this->lockout->registerFailure($user, 'auth.mfa_failed')) {
                $this->sessions->revoke($session);
            }

            return false;
        }
        $session->forceFill(['mfa_passed' => true])->save();
        $this->lockout->reset($user);
        $this->lockout->activity($user, 'auth.mfa_passed');

        return true;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, string $password): array
    {
        $this->reauthenticate($user, $password);
        if (! $this->hasActiveFactor($user)) {
            throw new BusinessRuleViolatedException('mfa_not_enabled', (string) trans('identity.mfa.mfa_not_enabled'));
        }

        return DB::transaction(function () use ($user): array {
            $codes = $this->replaceRecoveryCodes($user);
            $this->audit->record('user.mfa_recovery_regenerated', 'user', $user->id, null, ['recovery_codes' => count($codes)]);

            return $codes;
        });
    }

    /**
     * Desactivar exige la clave actual y queda auditado. Si un rol o la organización lo exige, no se permite.
     */
    public function disable(User $user, string $password): void
    {
        $this->reauthenticate($user, $password);
        if (! $this->hasActiveFactor($user)) {
            throw new BusinessRuleViolatedException('mfa_not_enabled', (string) trans('identity.mfa.mfa_not_enabled'));
        }
        if ($this->isRequired($user)) {
            throw new BusinessRuleViolatedException('mfa_required_by_policy', (string) trans('identity.mfa.mfa_required_by_policy'));
        }
        DB::transaction(function () use ($user): void {
            DB::table('user_mfa_factors')->where('user_id', $user->id)->whereNull('disabled_at')->update(['disabled_at' => now()]);
            DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->delete();
            $this->audit->record('user.mfa_disabled', 'user', $user->id, ['mfa_enabled' => true], ['mfa_enabled' => false]);
            $this->lockout->activity($user, 'auth.mfa_disabled');
        });
    }

    private function reauthenticate(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password_hash)) {
            throw ValidationException::withMessages(['password' => [(string) trans('identity.password.current_wrong')]]);
        }
    }

    private function activeFactor(User $user): ?\stdClass
    {
        /** @var \stdClass|null $row */
        $row = DB::table('user_mfa_factors')->where('user_id', $user->id)->where('kind', 'totp')
            ->whereNotNull('confirmed_at')->whereNull('disabled_at')->orderByDesc('confirmed_at')->first();

        return $row;
    }

    /**
     * @return list<string>
     */
    private function replaceRecoveryCodes(User $user): array
    {
        DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)->delete();
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = Str::lower(Str::random(10));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
            DB::table('user_mfa_recovery_codes')->insert([
                'tenant_id' => TenancyScope::requireTenant(),
                'user_id' => $user->id,
                'code_hash' => self::hashCode($raw),
            ]);
        }

        return $codes;
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $normalized = Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        if (strlen($normalized) !== 10) {
            return false;
        }

        return DB::table('user_mfa_recovery_codes')->where('user_id', $user->id)
            ->where('code_hash', self::hashCode($normalized))->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }

    private static function hashCode(string $normalized): string
    {
        return hash_hmac('sha256', 'mfa-recovery|'.$normalized, (string) config('app.key'));
    }

    private function stepTime(int $step): Carbon
    {
        return Carbon::createFromTimestamp(Totp::stepStart($step) + 1);
    }
}
