<?php

namespace App\Shared\Tenancy\Resolution;

/**
 * Organización resuelta para la petición, con el origen de la resolución.
 */
final readonly class ResolvedTenant
{
    public function __construct(
        public string $tenantId,
        public string $status,
        public string $source,
    ) {}
}
