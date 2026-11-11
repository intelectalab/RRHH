<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Notifications\Notifier;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\DB;

/**
 * RN-SEG-07 · Intentos fallidos seguidos (clave o segundo factor) y bloqueo temporal.
 * Al llegar al tope configurado la cuenta se bloquea y se avisa al titular.
 */
final class AccountLockout
{
    public function __construct(
        private readonly ParameterResolver $parameters,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return bool true si este fallo bloqueó la cuenta
     */
    public function registerFailure(User $user, string $activity): bool
    {
        $max = max(3, (int) ($this->parameters->value('seguridad.intentos_maximos') ?? 5));
        $minutes = max(5, (int) ($this->parameters->value('seguridad.bloqueo_minutos') ?? 15));
        $failed = (int) DB::table('users')->where('id', $user->id)->value('failed_attempts') + 1;
        $this->activity($user, $activity);

        if ($failed < $max) {
            DB::table('users')->where('id', $user->id)->update(['failed_attempts' => $failed]);

            return false;
        }
        DB::table('users')->where('id', $user->id)->update(['failed_attempts' => 0, 'locked_until' => now()->addMinutes($minutes)]);
        $this->activity($user, 'auth.locked');
        $this->notifier->notify('account.security', [$user->id], ['event' => (string) trans('identity.password.locked_event', ['minutes' => $minutes])], 'user', $user->id);

        return true;
    }

    public function reset(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update(['failed_attempts' => 0]);
    }

    public function activity(User $user, string $activity): void
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
