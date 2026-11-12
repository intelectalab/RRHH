<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * SAS-3 · Plan comercial y complemento: borrador → activo (se vende) → inactivo (no se vende, pero sus
 * suscriptores conservan sus condiciones, RN-SAS-11). Un plan no se elimina.
 */
final class PlanStateMachine extends StateMachine
{
    public const ACTIVATE = 'activate';

    public const RETIRE = 'retire';

    public function transitions(): array
    {
        return [
            'draft' => [self::ACTIVATE => 'active'],
            'active' => [self::RETIRE => 'inactive'],
            'inactive' => [self::ACTIVATE => 'active'],
        ];
    }
}
