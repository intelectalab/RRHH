<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * PER-1 · ME-19 · Periodo de planilla. «calculate» lo ejecuta PER-4 y «mark-paid» PER-10; «account» lo marca
 * el asiento exportado (PRV). Antes del cálculo, la recepción de incidencias se puede cerrar y reabrir.
 */
final class PayrollPeriodStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'open' => ['close-incidents' => 'incidents_closed', 'calculate' => 'calculated'],
            'incidents_closed' => ['reopen-incidents' => 'open', 'calculate' => 'calculated'],
            'calculated' => ['calculate' => 'calculated', 'submit' => 'in_review'],
            'in_review' => ['approve' => 'approved', 'reject' => 'calculated'],
            'approved' => ['close' => 'closed', 'reopen-calculation' => 'calculated'],
            'closed' => ['reopen' => 'open', 'mark-paid' => 'paid'],
            'paid' => ['account' => 'accounted'],
            'accounted' => [],
        ];
    }
}
