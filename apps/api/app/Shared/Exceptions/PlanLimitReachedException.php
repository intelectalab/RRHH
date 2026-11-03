<?php

namespace App\Shared\Exceptions;

final class PlanLimitReachedException extends AyniException
{
    public function __construct(string $limit, int $current, int $max, ?string $message = null)
    {
        parent::__construct(
            errorCode: 'plan_limit_reached',
            statusCode: 403,
            message: $message,
            meta: [
                'limit' => $limit,
                'current' => $current,
                'max' => $max,
            ],
        );
    }
}
