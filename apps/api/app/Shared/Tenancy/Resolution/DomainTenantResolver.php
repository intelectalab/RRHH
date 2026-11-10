<?php

namespace App\Shared\Tenancy\Resolution;

use Illuminate\Http\Request;

/**
 * Resuelve la organización por el host de la petición:
 *  1. Dominio propio verificado (marca blanca) en tenant_domains.
 *  2. Subdominio `{slug}.{tenancy.base_domain}`.
 */
final class DomainTenantResolver implements TenantResolver
{
    public function __construct(private readonly TenantDirectory $directory) {}

    public function resolve(Request $request): ?ResolvedTenant
    {
        $host = strtolower($request->getHost());
        $baseDomain = strtolower((string) config('tenancy.base_domain'));

        if ($host === '' || $host === $baseDomain) {
            return null;
        }

        $suffix = '.'.$baseDomain;
        if (str_ends_with($host, $suffix)) {
            $slug = substr($host, 0, -strlen($suffix));

            // Solo un nivel de subdominio y no reservado.
            /** @var array<int, string> $reserved */
            $reserved = (array) config('tenancy.reserved_subdomains', []);
            if ($slug === '' || str_contains($slug, '.') || in_array($slug, $reserved, true)) {
                return null;
            }

            return $this->directory->findBySlug($slug);
        }

        return $this->directory->findByVerifiedDomain($host);
    }
}
