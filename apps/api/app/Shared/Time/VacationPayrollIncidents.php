<?php

namespace App\Shared\Time;

/**
 * VAC-6 · Puerto de lectura para la planilla: ventas de días de vacaciones con acuerdo firmado en un periodo,
 * con el concepto del paquete de país que las paga. El módulo de nómina lo consume; nada se calcula aquí.
 */
interface VacationPayrollIncidents
{
    /**
     * @return list<array{agreement_id: string, days: string, effective_on: string, concept_code: string}>
     */
    public function sales(string $employmentId, string $from, string $to): array;
}
