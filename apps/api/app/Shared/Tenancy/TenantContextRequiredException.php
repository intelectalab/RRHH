<?php

namespace App\Shared\Tenancy;

use App\Shared\Exceptions\AyniException;

final class TenantContextRequiredException extends AyniException
{
    public function __construct(string $message = 'Se requiere un contexto de organización activo para esta operación.')
    {
        parent::__construct(
            errorCode: 'missing_tenant_context',
            statusCode: 400,
            message: $message,
        );
    }
}
