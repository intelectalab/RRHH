<?php

namespace App\Shared\Tenancy;

trait TenantAware
{
    public ?string $tenantId = null;

    public function initializeTenantAware(): void
    {
        $this->tenantId = TenancyScope::current();
    }

    public function restoreTenantContext(): void
    {
        if ($this->tenantId !== null) {
            TenancyScope::set($this->tenantId);
        }
    }
}
