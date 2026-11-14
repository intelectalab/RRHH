<?php

namespace App\Shared\Time;

use App\Shared\Exceptions\AyniException;

/**
 * RN-VAC-04 · Los días pedidos superan el saldo disponible más el adelanto permitido por la política.
 */
final class InsufficientVacationBalance extends AyniException
{
    public function __construct(string $requested, string $available, string $advance)
    {
        parent::__construct(
            errorCode: 'insufficient_vacation_balance',
            statusCode: 422,
            message: (string) trans('vacations.insufficient_balance', ['requested' => $requested, 'available' => $available, 'advance' => $advance]),
            meta: ['requested_days' => $requested, 'available_days' => $available, 'advance_days' => $advance],
        );
    }
}
