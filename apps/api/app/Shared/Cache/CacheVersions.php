<?php

namespace App\Shared\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Versiones de plataforma para invalidar de una vez lo guardado por todas las organizaciones (SAS-7: un
 * cambio de bandera en la consola). Solo guarda un identificador por nombre; los datos de cada
 * organización siguen en TenantCache, con la versión dentro de su clave.
 */
final class CacheVersions
{
    public function current(string $name): string
    {
        return (string) Cache::get('platform:version:'.$name, '0');
    }

    public function bump(string $name): void
    {
        Cache::forever('platform:version:'.$name, (string) Str::uuid());
    }
}
