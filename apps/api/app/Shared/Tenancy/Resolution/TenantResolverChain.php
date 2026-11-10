<?php

namespace App\Shared\Tenancy\Resolution;

use Illuminate\Http\Request;

/**
 * Aplica las estrategias de resolución en orden y devuelve la primera que resuelve.
 *
 * Orden: dominio/subdominio → cabecera de pruebas (solo testing).
 * La sesión y el token (SEG) no eligen la organización: se validan contra la
 * resuelta por el host en ResolveTenantContext (credencial de otra organización → 404).
 */
final class TenantResolverChain
{
    /** @var array<int, TenantResolver> */
    private array $resolvers;

    public function __construct(DomainTenantResolver $domain, TestingHeaderTenantResolver $testing)
    {
        $this->resolvers = [$domain, $testing];
    }

    public function resolve(Request $request): ?ResolvedTenant
    {
        foreach ($this->resolvers as $resolver) {
            $resolved = $resolver->resolve($request);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }
}
