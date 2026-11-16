<?php

namespace App\Shared\Organization;

/**
 * POS-1 · POS-2 · Contrato para los módulos que asignan personas a posiciones (asignaciones de HIS,
 * ingreso de COL, contratación de ATS). Lo implementa el módulo de Organización.
 *
 * - RN-POS-02: una posición admite un ocupante a la vez, o tantos como cupos si es compartida.
 * - RN-POS-03: una posición congelada, cerrada o aún propuesta no admite ocupantes.
 * - POS-2: ocupada o vacante se deriva de las asignaciones vigentes; quien cambia una asignación
 *   llama a sync() en la misma transacción (y una tarea diaria aplica las que empiezan a regir).
 */
interface PositionOccupancy
{
    /**
     * Responde 422 explicando el motivo si la posición no admite a esa relación laboral desde esa fecha.
     *
     * @param  string|null  $employmentId  relación que se asigna (no cuenta contra sus propios cupos)
     */
    public function assertAssignable(string $positionId, string $from, ?string $to = null, ?string $employmentId = null): void;

    /** Ocupantes vigentes de la posición en la fecha (hoy si es null). */
    public function occupiedSeats(string $positionId, ?string $date = null): int;

    /**
     * Recalcula el estado ocupada / vacante con las asignaciones vigentes hoy (RN-POS-04).
     * Devuelve el estado resultante, o null si la posición no existe.
     */
    public function sync(string $positionId, ?string $userId = null): ?string;

    /**
     * POS-6 · Con la política «contratar solo contra posición» desactivada, un ingreso sin posición crea
     * una posición implícita aprobada con la estructura del ingreso (puesto, unidad, sede, centro de costo)
     * y vigente desde esa fecha; quien la crea luego asigna a la persona y llama a sync(). Devuelve su id.
     *
     * @param  array{job_profile_id: string, org_unit_id: string, site_id?: string|null, cost_center_id?: string|null}  $structure
     */
    public function createImplicit(string $legalEntityId, array $structure, string $from, ?string $userId = null): string;
}
