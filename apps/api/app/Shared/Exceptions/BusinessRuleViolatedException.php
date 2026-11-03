<?php

namespace App\Shared\Exceptions;

final class BusinessRuleViolatedException extends AyniException
{
    public function __construct(string $rule, ?string $message = null)
    {
        parent::__construct(
            errorCode: 'business_rule_violated',
            statusCode: 422,
            message: $message,
            meta: ['rule' => $rule],
        );
    }
}
