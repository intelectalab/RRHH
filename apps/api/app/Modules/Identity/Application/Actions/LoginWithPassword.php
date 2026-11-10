<?php

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Security\BlindIndex;
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
            if ($user !== null && ! $valid) {
                DB::table('users')->where('id', $user->id)->increment('failed_attempts');
                $this->activity($user, 'auth.login_failed');
            }

            return null;
        }

        $changes = ['failed_attempts' => 0, 'last_login_at' => now()];
        if (Hash::needsRehash((string) $hash)) {
            $changes['password_hash'] = Hash::make($password);
        }
        $user->forceFill($changes)->save();
        $this->activity($user, 'auth.login');

        return $user;
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
