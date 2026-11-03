<?php

namespace App\Shared\Exceptions;

final class ModuleNotEnabledException extends AyniException
{
    public function __construct(string $module, ?string $message = null)
    {
        parent::__construct(
            errorCode: 'module_not_enabled',
            statusCode: 403,
            message: $message,
            meta: ['module' => $module],
        );
    }
}
