<?php

namespace App\Shared\Exceptions;

final class InvalidFilterException extends AyniException
{
    /**
     * @param  array<string>  $allowed
     */
    public function __construct(string $message, array $allowed = [])
    {
        parent::__construct(
            errorCode: 'invalid_filter',
            statusCode: 400,
            message: $message,
            meta: ! empty($allowed) ? ['allowed' => $allowed] : [],
        );
    }
}
