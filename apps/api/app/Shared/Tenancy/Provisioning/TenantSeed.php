<?php

namespace App\Shared\Tenancy\Provisioning;

/**
 * SAS-2 · Datos con los que se da de alta una organización: su primer administrador y su primera
 * empresa. Cada módulo toma lo suyo desde su paso de alta (TenantProvisioningStep).
 */
final class TenantSeed
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $slug,
        public readonly string $countryCode,
        public readonly string $currency,
        public readonly string $timezone,
        public readonly string $locale,
        public readonly string $adminName,
        public readonly string $adminEmail,
        public readonly string $legalName,
        public readonly string $taxId,
        public readonly string $payrollRegime,
    ) {}

    /**
     * Dirección absoluta de la organización (subdominio), para enlaces de invitación.
     */
    public function appUrl(): string
    {
        return rtrim(str_replace('{slug}', $this->slug, (string) config('tenancy.app_url', 'http://{slug}.localhost:3000')), '/');
    }
}
