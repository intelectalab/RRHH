<?php

namespace App\Shared\Time;

/**
 * VAC-6 · BSO-5 · Puerto de lectura para la planilla, desde el libro de vacaciones (sin redigitar nada): ventas de
 * días con acuerdo firmado, días de descanso gozados en un rango con su periodo vacacional y periodos vencidos
 * con días sin gozar. El módulo de nómina lo consume; aquí no se calcula ningún monto.
 */
interface VacationPayrollIncidents
{
    /**
     * @return list<array{agreement_id: string, days: string, effective_on: string, concept_code: string}>
     */
    public function sales(string $employmentId, string $from, string $to): array;

    /**
     * Días de descanso vacacional (goce o adelanto aprobado) que caen en el rango, por solicitud y con el periodo
     * vacacional al que se imputan. Los días de una solicitud que cruza el rango se reparten por días calendario.
     *
     * @return list<array{request_id: string, accrual_period_id: ?string, period_start: ?string, from: string, to: string, days: string}>
     */
    public function taken(string $employmentId, string $from, string $to): array;

    /**
     * Periodos vacacionales cuya fecha límite de goce venció antes de la fecha con días aún sin gozar: dan lugar
     * a la indemnización por vacaciones no gozadas (para revisión).
     *
     * @return list<array{accrual_period_id: string, period_start: string, enjoy_by: string, days: string, concept_code: string}>
     */
    public function expired(string $employmentId, string $asOf): array;
}
