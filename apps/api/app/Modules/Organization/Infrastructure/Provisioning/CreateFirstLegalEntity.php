<?php

namespace App\Modules\Organization\Infrastructure\Provisioning;

use App\Modules\Organization\Application\LegalEntityService;
use App\Shared\Tenancy\Provisioning\TenantProvisioningStep;
use App\Shared\Tenancy\Provisioning\TenantSeed;

/**
 * SAS-2 · Alta de organización: su primera empresa, con las mismas validaciones que el alta normal
 * (número fiscal del país, régimen laboral del país).
 */
final class CreateFirstLegalEntity implements TenantProvisioningStep
{
    public function __construct(private readonly LegalEntityService $entities) {}

    public function order(): int
    {
        return 10;
    }

    public function provision(TenantSeed $seed): void
    {
        $this->entities->create([
            'name' => $seed->legalName,
            'tax_id' => $seed->taxId,
            'country' => $seed->countryCode,
            'currency' => $seed->currency,
            'timezone' => $seed->timezone,
            'payroll_regime' => $seed->payrollRegime,
        ]);
    }
}
