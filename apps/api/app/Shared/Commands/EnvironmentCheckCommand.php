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
            $criticalKeys = array_merge($criticalKeys, ['TENANCY_BASE_DOMAIN', 'TENANCY_APP_URL']);
            // QA-17 · Las credenciales de S3 solo se exigen si los archivos van a S3 (FILESYSTEM_DISK=s3).
            if ((getenv('FILESYSTEM_DISK') ?: 's3') === 's3') {
                $criticalKeys = array_merge($criticalKeys, [
                    'AWS_ACCESS_KEY_ID',
                    'AWS_SECRET_ACCESS_KEY',
                    'AWS_BUCKET',
                ]);
            }
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

            // QA-17 · La clave de desarrollo de docker-compose.yml nunca sirve fuera de la PC del equipo.
            if (getenv('APP_KEY') === 'base64:J8S6fN2wP0vR4yT7uK1mE9qL3zX5cB8aV4nC2mD1fG0=') {
                $this->error('Error de seguridad: APP_KEY no puede ser la clave de desarrollo en producción o staging.');

                return self::FAILURE;
            }

            if (in_array(getenv('TENANCY_BASE_DOMAIN'), ['localhost', '127.0.0.1'], true)) {
                $this->error('Error de configuración: TENANCY_BASE_DOMAIN debe ser el dominio público (p. ej. staging.gestiahr.com).');

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
