<?php

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\AccountLockout;
use App\Modules\Identity\Application\Mfa\MfaService;
use App\Modules\Identity\Application\PasswordPolicy;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SEG-1 · Ingreso con correo (o documento) y clave dentro de la organización activa.
 *
 * Devuelve null ante cualquier fallo, sin distinguir si el identificador existe:
 * el mensaje de error no revela cuentas (criterio de SEG-1). Siempre se verifica
 * un hash para que el tiempo de respuesta no delate la existencia.
 */
final class LoginWithPassword
{
    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly AccountLockout $lockout,
        private readonly MfaService $mfa,
    ) {}

    private static ?string $dummyHash = null;

    public function __invoke(string $kind, string $identifier, string $password): ?User
    {
        /** @var UserIdentity|null $identity */
        $identity = UserIdentity::query()
            ->with('user')
            ->where('kind', $kind)
            ->where('identifier_hash', BlindIndex::for($kind, $identifier))
            ->first();

        $user = $identity?->user;
        $hash = $user?->password_hash;

        $valid = Hash::check($password, is_string($hash) && $hash !== '' ? $hash : self::dummyHash());

        if ($user === null || ! $valid || ! $user->canSignIn()) {
            $reason = $user === null ? 'unknown_identifier' : (! $user->canSignIn() ? 'locked_or_inactive' : 'wrong_password');
            $this->attempt($kind, $identifier, $user, false, $reason);
            if ($user !== null && ! $valid && $user->canSignIn()) {
                $this->lockout->registerFailure($user, 'auth.login_failed');
            }

            return null;
        }

        $this->attempt($kind, $identifier, $user, true, null);
        $changes = ['last_login_at' => now()];
        // SEG-3: con segundo factor activo, el contador de fallos solo se reinicia al superar el código;
        // así no se puede volver a probar códigos ingresando la clave otra vez.
        if (! $this->mfa->hasActiveFactor($user)) {
            $changes['failed_attempts'] = 0;
        }
        // SEG-5: clave vencida según la política → se pide cambiarla antes de seguir.
        if ($this->policy->isExpired($user)) {
            $changes['must_change_password'] = true;
        }
        if (Hash::needsRehash((string) $hash)) {
            $changes['password_hash'] = Hash::make($password);
        }
        $user->forceFill($changes)->save();
        $this->lockout->activity($user, 'auth.login');

        return $user;
    }

    private function attempt(string $kind, string $identifier, ?User $user, bool $ok, ?string $reason): void
    {
        DB::table('login_attempts')->insert([
            'tenant_id' => TenancyScope::requireTenant(),
            'identifier_hash' => BlindIndex::for($kind, $identifier),
            'user_id' => $user?->id,
            'succeeded' => $ok,
            'failure_reason' => $reason,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            'occurred_at' => now(),
        ]);
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('ayni-dummy-'.bin2hex(random_bytes(8)));
    }
}
