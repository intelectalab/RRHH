<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Console\ConsoleAuth;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Console\Command;

/**
 * SAS-1 · Restablece el segundo factor de un usuario de la consola que perdió su teléfono.
 * Solo desde la terminal del servidor: cierra sus sesiones y el próximo ingreso lo configura de nuevo.
 */
final class ResetPlatformMfaCommand extends Command
{
    protected $signature = 'platform:reset-mfa {email : Correo del usuario de la consola}';

    protected $description = 'Restablece la verificación en dos pasos de un usuario de la consola SaaS';

    public function handle(ConsoleAuth $auth): int
    {
        /** @var PlatformUser|null $user */
        $user = PlatformUser::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $this->argument('email')))])->first();
        if ($user === null) {
            $this->error('No existe un usuario de la consola con ese correo.');

            return self::FAILURE;
        }

        $auth->resetMfa($user);
        $this->info('Verificación en dos pasos restablecida; se cerraron sus sesiones.');

        return self::SUCCESS;
    }
}
