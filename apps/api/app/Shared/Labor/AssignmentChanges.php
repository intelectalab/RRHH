<?php

namespace App\Shared\Labor;

/**
 * Contrato entre módulos (CON → COL): una adenda firmada que cambia el puesto, la posición, la unidad o la
 * sede registra el movimiento con vigencia en la asignación (RN-CON-08). Core HR lo implementa con sus
 * reglas de movimientos (COL-5: cierra la asignación vigente y abre otra; sincroniza las posiciones);
 * Contratos solo lo pide, sin importar sus clases.
 */
interface AssignmentChanges
{
    /** Campos de la asignación que una adenda puede cambiar. */
    public const FIELDS = ['position_id', 'job_profile_id', 'org_unit_id', 'site_id', 'cost_center_id', 'manager_employment_id', 'work_modality'];

    /**
     * Registra el movimiento desde $effectiveOn y devuelve el id de la acción de personal que lo respalda.
     *
     * @param  array<string, string|null>  $changes  solo claves de FIELDS
     */
    public function apply(string $employmentId, array $changes, string $effectiveOn, string $reason, ?string $userId): string;
}
