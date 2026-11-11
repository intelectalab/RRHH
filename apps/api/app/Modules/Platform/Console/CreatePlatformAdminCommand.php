<?php

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * SAS-1 · Crea un usuario de la consola (por defecto, el propietario).
 *
 * La clave nunca va en el código ni en el seeder: se toma de PLATFORM_ADMIN_PASSWORD o se genera
 * y se muestra una sola vez. El segundo factor se configura en el primer ingreso (obligatorio).
 *
 *   PLATFORM_ADMIN_PASSWORD='…' php artisan platform:create-admin admin@empresa.pe "Nombre Apellido"
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
        {email : Correo del usuario de la consola}
        {name : Nombre completo}
        {--role=superadmin : superadmin, support, billing o readonly}';

    protected $description = 'Crea un usuario de la consola SaaS (clave por variable de entorno o generada y mostrada una vez)';

    public function handle(ConsoleAudit $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        $role = (string) $this->option('role');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $name === '') {
            $this->error('Correo o nombre no válidos.');

            return self::FAILURE;
        }
        if (! in_array($role, PlatformUser::ROLES, true)) {
            $this->error('Rol no válido. Use: '.implode(', ', PlatformUser::ROLES).'.');

            return self::FAILURE;
        }
        if (PlatformUser::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->error('Ya existe un usuario de la consola con ese correo.');

            return self::FAILURE;
        }

        $fromEnv = getenv('PLATFORM_ADMIN_PASSWORD');
        $password = is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : Str::password(20);
        $min = (int) config('platform.password_min_length');
        if (mb_strlen($password) < $min) {
            $this->error("La clave debe tener al menos {$min} caracteres.");

            return self::FAILURE;
        }

        $user = PlatformUser::create([
            'email' => $email,
            'full_name' => $name,
            'role' => $role,
            'status' => 'active',
            'password_hash' => Hash::make($password),
        ]);
        $audit->record('console.user_created', null, 'platform_user', $user->id, null, ['email' => $email, 'role' => $role, 'source' => 'cli']);

        $this->info("Usuario de la consola creado: {$email} ({$role}).");
        if (! (is_string($fromEnv) && $fromEnv !== '')) {
            $this->warn('Clave generada (se muestra una sola vez; guárdela en un gestor de claves):');
            $this->line($password);
        }
        $this->line('En el primer ingreso a /plataforma/ingresar se configura la verificación en dos pasos (obligatoria).');

        return self::SUCCESS;
    }
}
