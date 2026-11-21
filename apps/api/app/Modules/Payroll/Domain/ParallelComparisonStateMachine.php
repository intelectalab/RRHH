<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * PER-13 · Comparación de la planilla en paralelo: abierta mientras se explican las diferencias; lista cuando
 * todas están explicadas (deja el informe en el archivo); se reabre si hace falta volver a revisarla.
 */
final class ParallelComparisonStateMachine extends StateMachine
{
    public function transitions(): array
    {
        return [
            'open' => ['mark_ready' => 'ready'],
            'ready' => ['reopen' => 'open'],
        ];
    }
}
