<?php

namespace App\Shared\Tenancy\Resolution;

use Illuminate\Http\Request;

/**
 * Estrategia de resolución de la organización activa a partir de una petición.
 *
 * Devuelve el tenant_id resuelto o null si esta estrategia no aplica a la petición.
 * Ver docs/02-arquitectura.md §5.1.
 */
interface TenantResolver
{
    public function resolve(Request $request): ?ResolvedTenant;
}
