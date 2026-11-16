<?php

namespace App\Shared\Labor;

/**
 * HIS-6 · C-11 · RN-HIS-01 · Servicio interno único de datos maestros de la relación laboral.
 *
 * Los demás módulos leen aquí el puesto, la posición, la unidad, la sede, el centro de costo, el jefe y el
 * sueldo vigentes a una fecha. Ninguno guarda una copia editable de esos datos: si necesita el valor de una
 * fecha lo vuelve a pedir (una prueba de arquitectura vigila columnas duplicadas en otras tablas).
 *
 * El servicio no aplica permisos: devuelve el dato a quien ya decidió que puede leerlo. Quien muestre el
 * sueldo a una persona comprueba el permiso de campo y deja la lectura en el registro de accesos (reglas 6 y 7).
 */
interface MasterData
{
    /**
     * Datos de la asignación vigente a la fecha; null si la relación no existe o no tenía asignación ese día.
     * El jefe es el de la asignación o, si no lo indica, quien ocupa la posición a la que reporta la suya.
     *
     * @return array{employment_id: string, as_of: string, legal_entity_id: string, job_profile_id: string, position_id: string|null, org_unit_id: string, site_id: string, cost_center_id: string|null, manager_employment_id: string|null, manager_source: 'assignment'|'position'|null, work_modality: string, valid_from: string, valid_to: string|null}|null
     */
    public function at(string $employmentId, string $asOf): ?array;

    /**
     * Varias relaciones a la misma fecha (las que no tienen asignación ese día no vienen).
     *
     * @param  list<string>  $employmentIds
     * @return array<string, array{employment_id: string, as_of: string, legal_entity_id: string, job_profile_id: string, position_id: string|null, org_unit_id: string, site_id: string, cost_center_id: string|null, manager_employment_id: string|null, manager_source: 'assignment'|'position'|null, work_modality: string, valid_from: string, valid_to: string|null}>
     */
    public function many(array $employmentIds, string $asOf): array;

    /** Relación laboral del jefe vigente a la fecha. */
    public function managerAt(string $employmentId, string $asOf): ?string;

    /**
     * Sueldo vigente a la fecha (monto decimal exacto como texto, nunca float).
     *
     * @return array{id: string, amount: string, currency: string, pay_frequency: string, salary_basis: string, valid_from: string, valid_to: string|null}|null
     */
    public function salaryAt(string $employmentId, string $asOf): ?array;
}
