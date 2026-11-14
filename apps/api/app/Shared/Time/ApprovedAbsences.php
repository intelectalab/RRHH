<?php

namespace App\Shared\Time;

/**
 * AUS-2 · Puerto para que asistencia (ASI), planilla y otros módulos sepan si un trabajador tiene una
 * ausencia aprobada en una fecha sin importar las clases del módulo de ausencias. Una ausencia aprobada que
 * afecta asistencia evita la falta de ese día (o de esas horas, si es un permiso por horas).
 */
interface ApprovedAbsences
{
    /**
     * Ausencias aprobadas (aprobada, en curso o finalizada) que cubren la fecha.
     *
     * @return list<array{id: string, absence_type_id: string, absence_type_code: string, unit: string, starts_on: string, ends_on: string, start_time: string|null, end_time: string|null, is_paid: bool, affects_attendance: bool}>
     */
    public function on(string $employmentId, string $date): array;

    /** ¿La fecha está justificada por una ausencia de día completo que afecta asistencia? */
    public function justifiesFullDay(string $employmentId, string $date): bool;
}
