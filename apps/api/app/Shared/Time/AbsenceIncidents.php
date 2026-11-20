<?php

namespace App\Shared\Time;

/**
 * AUS-6 · Puerto que la planilla (NOM-5) lee para conocer, por trabajador y periodo, los días y horas de cada
 * tipo de ausencia con su efecto remunerativo, sin importar las clases del módulo de ausencias.
 *
 * El resumen es reproducible: con los mismos datos devuelve lo mismo (y la misma huella) y explica cada día.
 * Sin el módulo de asistencia, los días laborados salen del calendario laboral, las ausencias aprobadas y las
 * incidencias importadas del periodo.
 */
interface AbsenceIncidents
{
    /**
     * @return array<string, mixed> resumen con days (un renglón por fecha), types (totales por tipo), totals, imported_incidents, source y fingerprint
     */
    public function summary(string $employmentId, string $from, string $to): array;

    /**
     * Diferencias contra el último resumen congelado al cerrar el periodo; null si el periodo no se cerró.
     *
     * @return array{closed_at: string, closed_fingerprint: string, changed: bool, days: list<array<string, mixed>>, types: list<array<string, mixed>>}|null
     */
    public function differences(string $employmentId, string $from, string $to): ?array;
}
