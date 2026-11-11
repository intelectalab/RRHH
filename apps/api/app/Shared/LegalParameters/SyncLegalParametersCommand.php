<?php

namespace App\Shared\LegalParameters;

use Illuminate\Console\Command;

/**
 * Carga o actualiza los parámetros legales del paquete de país (idempotente). Se ejecuta al desplegar
 * y desde DatabaseSeeder; corre con la conexión de la plataforma (legal_parameters no es de una organización).
 */
final class SyncLegalParametersCommand extends Command
{
    protected $signature = 'gestia:legal-parameters:sync {country=PE : Código ISO del país}';

    protected $description = 'Carga los parámetros legales con vigencia del paquete de país';

    public function handle(LegalParameters $parameters): int
    {
        $country = strtoupper((string) $this->argument('country'));
        $result = $parameters->sync($country);
        $this->info("{$country}: {$result['inserted']} vigencias nuevas, {$result['updated']} actualizadas.");

        return self::SUCCESS;
    }
}
