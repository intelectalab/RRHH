<?php

namespace App\Shared\Tenancy\Resolution;

use Illuminate\Http\Request;

/**
 * Solo en el entorno de pruebas: permite fijar la organización con la cabecera
 * X-Tenant-Id para que las pruebas Feature no dependan de subdominios.
 *
 * En cualquier otro entorno la cabecera se ignora: un cliente no puede elegir
 * la organización enviando un identificador.
 */
final class TestingHeaderTenantResolver implements TenantResolver
{
    public const HEADER = 'X-Tenant-Id';

    public function __construct(private readonly TenantDirectory $directory) {}

    public function resolve(Request $request): ?ResolvedTenant
    {
        if (! app()->environment('testing')) {
            return null;
        }

        $header = $request->header(self::HEADER);

        return is_string($header) ? $this->directory->findById($header, 'testing_header') : null;
    }
}
