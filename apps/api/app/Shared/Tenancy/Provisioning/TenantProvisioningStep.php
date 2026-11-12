<?php

namespace App\Shared\Tenancy\Provisioning;

/**
 * SAS-2 · Paso del alta de una organización que aporta un módulo (p. ej. Organización crea la primera
 * empresa; Identidad invita al primer administrador). Corre dentro de la transacción del alta y con
 * el contexto de la organización nueva: si falla, el alta entera se deshace.
 */
interface TenantProvisioningStep
{
    /** Orden de ejecución (menor primero). */
    public function order(): int;

    public function provision(TenantSeed $seed): void;
}
