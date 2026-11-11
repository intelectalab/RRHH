<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SEG-5 · Política de claves de la organización (Configuración → Seguridad): longitud mínima,
 * letras y números, claves anteriores que no se repiten y vencimiento opcional.
 */
final class PasswordPolicy
{
    public function __construct(private readonly ParameterResolver $parameters) {}

    public function minLength(): int
    {
        return max(10, (int) ($this->parameters->value('seguridad.clave_longitud_minima') ?? 12));
    }

    /**
     * @throws ValidationException
     */
    public function assertAcceptable(User $user, string $password, string $field = 'password'): void
    {
        $errors = [];
        if (mb_strlen($password) < $this->minLength()) {
            $errors[] = (string) trans('identity.password.too_short', ['min' => $this->minLength()]);
        }
        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            $errors[] = (string) trans('identity.password.letters_numbers');
        }
        if ($this->isReused($user, $password)) {
            $errors[] = (string) trans('identity.password.reused', ['n' => $this->historySize()]);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages([$field => $errors]);
        }
    }

    /**
     * Guarda la clave nueva en el historial (solo el hash) y recorta lo que excede la política.
     */
    public function remember(User $user, string $hash): void
    {
        // La primera vez también se recuerda la clave que se está reemplazando.
        if ($user->password_hash !== null && ! DB::table('password_history')->where('user_id', $user->id)->exists()) {
            DB::table('password_history')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'password_hash' => $user->password_hash, 'created_at' => now()->subSecond()]);
        }
        DB::table('password_history')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'password_hash' => $hash, 'created_at' => now()]);
        $keep = max(1, $this->historySize());
        $old = DB::table('password_history')->where('user_id', $user->id)->orderByDesc('created_at')->offset($keep)->limit(100)->pluck('id');
        if ($old->isNotEmpty()) {
            DB::table('password_history')->whereIn('id', $old)->delete();
        }
    }

    /** Clave vencida según seguridad.clave_vencimiento_dias (0 = no vence). */
    public function isExpired(User $user): bool
    {
        $days = (int) ($this->parameters->value('seguridad.clave_vencimiento_dias') ?? 0);

        return $days > 0 && $user->password_changed_at !== null && $user->password_changed_at->lt(now()->subDays($days));
    }

    private function historySize(): int
    {
        return max(0, (int) ($this->parameters->value('seguridad.clave_historial') ?? 0));
    }

    private function isReused(User $user, string $password): bool
    {
        $n = $this->historySize();
        if ($n === 0) {
            return false;
        }
        $hashes = DB::table('password_history')->where('user_id', $user->id)->orderByDesc('created_at')->limit($n)->pluck('password_hash');
        if ($user->password_hash !== null && $hashes->isEmpty()) {
            $hashes->push($user->password_hash);
        }
        foreach ($hashes as $hash) {
            if (Hash::check($password, (string) $hash)) {
                return true;
            }
        }

        return false;
    }
}
