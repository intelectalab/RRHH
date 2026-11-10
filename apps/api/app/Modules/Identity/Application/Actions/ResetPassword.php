<?php

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Application\SessionManager;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * SEG-1 · Restablece la clave con un enlace vigente y de un solo uso.
 * Al cambiar la clave se cierran todas las sesiones abiertas (SEG-4).
 */
final class ResetPassword
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function __invoke(string $rawToken, string $newPassword): bool
    {
        return DB::transaction(function () use ($rawToken, $newPassword): bool {
            /** @var UserToken|null $token */
            $token = UserToken::query()
                ->where('purpose', UserToken::PURPOSE_PASSWORD_RESET)
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if ($token === null || ! $token->isUsable()) {
                return false;
            }

            /** @var User|null $user */
            $user = User::query()->find($token->user_id);
            if ($user === null || ! in_array($user->status, ['active', 'locked'], true)) {
                return false;
            }

            $token->forceFill(['consumed_at' => now()])->save();
            $user->forceFill([
                'password_hash' => Hash::make($newPassword),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'failed_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->sessions->revokeAllFor($user);

            DB::table('activity_events')->insert([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'activity' => 'auth.password_reset',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'occurred_at' => now(),
            ]);

            return true;
        });
    }
}
