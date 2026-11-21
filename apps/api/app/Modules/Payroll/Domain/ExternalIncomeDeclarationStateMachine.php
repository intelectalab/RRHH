<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * DEC-9 · Declaración jurada de rentas de otros empleadores. Nace recibida (la presenta el trabajador en el portal o
 * RRHH la registra); RRHH la valida o la rechaza con motivo; una validada deja de aplicar al ser reemplazada por otra
 * declaración del mismo empleador y ejercicio, o retirada a mano (supersede). Nada se sobrescribe.
 */
final class ExternalIncomeDeclarationStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'received' => ['validate' => 'validated', 'reject' => 'rejected'],
            'validated' => ['supersede' => 'superseded'],
            'superseded' => [],
            'rejected' => [],
        ];
    }
}
