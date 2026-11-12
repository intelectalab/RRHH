<?php

namespace App\Modules\Identity\Infrastructure\Provisioning;

use App\Modules\Identity\Application\UserService;
use App\Shared\Tenancy\Provisioning\TenantProvisioningStep;
use App\Shared\Tenancy\Provisioning\TenantSeed;

/**
 * SAS-2 · Alta de organización: el primer administrador queda invitado con el rol «admin_empresa».
 */
final class InviteFirstAdmin implements TenantProvisioningStep
{
    public function __construct(private readonly UserService $users) {}

    public function order(): int
    {
        return 20;
    }

    public function provision(TenantSeed $seed): void
    {
        $this->users->createFirstAdmin($seed->adminName, $seed->adminEmail, $seed->appUrl());
    }
}
