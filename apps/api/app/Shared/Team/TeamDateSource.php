<?php

namespace App\Shared\Team;

/**
 * MSS-4 · Fuente de fechas clave del equipo (cumpleaños, aniversarios, fin del periodo de prueba, contratos por
 * vencer). Cada módulo registra las suyas en TeamDateRegistry; quien la consume ya resolvió el equipo del
 * manager (RN-MSS-01) y comprobó el permiso de la fuente.
 */
interface TeamDateSource
{
    /** Clave estable del tipo de fecha (birthday, anniversary, probation_end, contract_end). */
    public function key(): string;

    /** Permiso que además exige ver esta fuente (el de «mi equipo» solo no basta). */
    public function permission(): string;

    /**
     * Fechas entre $from y $to (inclusive) de las relaciones laborales indicadas. Nunca devuelve el año de
     * nacimiento ni la edad; years solo en aniversarios.
     *
     * @param  list<string>  $employmentIds
     * @return list<array{employment_id: string, date: string, years?: int}>
     */
    public function items(array $employmentIds, string $from, string $to): array;
}
