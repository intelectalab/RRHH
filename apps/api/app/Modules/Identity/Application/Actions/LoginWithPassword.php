<?php

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\PasswordPolicy;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Notifications\Notifier;
use App\Shared\Security\BlindIndex;
use App\Shared\Settings\ParameterResolver;
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
        private readonly ParameterResolver $parameters,
        private readonly PasswordPolicy $policy,
        private readonly Notifier $notifier,
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
                $this->registerFailure($user);
            }

            return null;
        }

        $this->attempt($kind, $identifier, $user, true, null);
        $changes = ['failed_attempts' => 0, 'last_login_at' => now()];
        // SEG-5: clave vencida según la política → se pide cambiarla antes de seguir.
        if ($this->policy->isExpired($user)) {
            $changes['must_change_password'] = true;
        }
        if (Hash::needsRehash((string) $hash)) {
            $changes['password_hash'] = Hash::make($password);
        }
        $user->forceFill($changes)->save();
        $this->activity($user, 'auth.login');

        return $user;
    }

    /**
     * RN-SEG-07: al llegar al tope de intentos seguidos la cuenta se bloquea por el tiempo
     * configurado y se avisa al titular (aviso de seguridad obligatorio y urgente).
     */
    private function registerFailure(User $user): void
    {
        $max = max(3, (int) ($this->parameters->value('seguridad.intentos_maximos') ?? 5));
        $minutes = max(5, (int) ($this->parameters->value('seguridad.bloqueo_minutos') ?? 15));
        $failed = (int) $user->failed_attempts + 1;
        $this->activity($user, 'auth.login_failed');

        if ($failed < $max) {
            DB::table('users')->where('id', $user->id)->update(['failed_attempts' => $failed]);

            return;
        }
        DB::table('users')->where('id', $user->id)->update(['failed_attempts' => 0, 'locked_until' => now()->addMinutes($minutes)]);
        $this->activity($user, 'auth.locked');
        $this->notifier->notify('account.security', [$user->id], ['event' => (string) trans('identity.password.locked_event', ['minutes' => $minutes])], 'user', $user->id);
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

    private function activity(User $user, string $activity): void
    {
        DB::table('activity_events')->insert([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'activity' => $activity,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'metadata' => json_encode(['ip' => request()->ip()], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }
}
