<?php

declare(strict_types=1);

namespace App\Shared\Commands;

use Illuminate\Console\Command;

class EnvironmentCheckCommand extends Command
{
    protected $signature = 'env:verify {--environment= : Entorno a verificar (production, staging, testing, local)}';

    protected $description = 'Verifica que las variables de entorno críticas y secretos estén configurados correctamente para el entorno';

    public function handle(): int
    {
        $env = (string) ($this->option('environment') ?: app()->environment());
        $this->info("Verificando configuración para entorno: {$env}");

        $criticalKeys = [
            'APP_KEY',
            'DB_HOST',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'REDIS_HOST',
        ];

        if (in_array($env, ['production', 'staging'], true)) {
            $criticalKeys = array_merge($criticalKeys, [
                'AWS_ACCESS_KEY_ID',
                'AWS_SECRET_ACCESS_KEY',
                'AWS_BUCKET',
            ]);
        }

        $missing = [];
        foreach ($criticalKeys as $key) {
            $value = getenv($key);
            if ($value === false || $value === '') {
                $missing[] = $key;
            }
        }

        if (in_array($env, ['production', 'staging'], true)) {
            // Verificar que no se usen credenciales predeterminadas o inseguras
            $dbPass = getenv('DB_PASSWORD');
            if ($dbPass === 'secret' || $dbPass === 'postgres') {
                $this->error('Error de seguridad: DB_PASSWORD no puede usar valores por defecto en producción o staging.');

                return self::FAILURE;
            }

            $appDebug = getenv('APP_DEBUG');
            if ($appDebug === 'true' || $appDebug === '1') {
                $this->error('Error de seguridad: APP_DEBUG debe ser false en producción y staging.');

                return self::FAILURE;
            }
        }

        if (! empty($missing)) {
            $this->error('Faltan variables críticas de entorno: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $this->info('✓ Todas las variables críticas y políticas del entorno son válidas.');

        return self::SUCCESS;
    }
}
