<?php

namespace App\Shared\Exceptions;

final class InvalidTransitionException extends AyniException
{
    /**
     * @param  array<int, string>  $allowedActions
     */
    public function __construct(string $currentStatus, array $allowedActions = [], ?string $message = null)
    {
        parent::__construct(
            errorCode: 'invalid_transition',
            statusCode: 409,
            message: $message,
            meta: [
                'current_status' => $currentStatus,
                'allowed_actions' => $allowedActions,
            ],
        );
    }
}
