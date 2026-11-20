<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * BSO-2 · Depósito de CTS de un trabajador: calculado con la planilla de CTS, depositado con la referencia del
 * banco (RN-BSO-06: emite la liquidación de depósito al legajo y al portal) y notificado al trabajador.
 */
final class CtsDepositStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'calculated' => ['deposit' => 'notified'],
            'approved' => ['deposit' => 'notified'],
            'deposited' => ['notify' => 'notified'],
            'notified' => [],
        ];
    }
}
