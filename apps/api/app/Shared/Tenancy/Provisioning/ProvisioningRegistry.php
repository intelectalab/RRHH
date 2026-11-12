<?php

namespace App\Shared\Tenancy\Provisioning;

/**
 * SAS-2 · Pasos del alta de una organización que registra cada módulo en su proveedor. La consola
 * (Modules/Platform) los ejecuta sin conocer a los módulos (regla de fronteras entre módulos).
 */
final class ProvisioningRegistry
{
    /** @var list<TenantProvisioningStep> */
    private array $steps = [];

    public function register(TenantProvisioningStep $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @return list<TenantProvisioningStep>
     */
    public function steps(): array
    {
        $steps = $this->steps;
        usort($steps, fn (TenantProvisioningStep $a, TenantProvisioningStep $b): int => $a->order() <=> $b->order());

        return $steps;
    }
}
