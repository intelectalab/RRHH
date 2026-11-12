<?php

namespace App\Shared\Organization;

/**
 * ORG-2 · ORG-4 · Contrato común para los módulos que asignan personas a la estructura
 * (asignaciones, posiciones, gastos, hojas de tiempo): una sede archivada o un centro de costo
 * cerrado o archivado no admiten nuevas asignaciones. Lo implementa el módulo de Organización.
 */
interface StructureAvailability
{
    /** La sede existe en la organización, está activa y (si se indica) pertenece a esa empresa o es compartida. */
    public function siteAcceptsAssignments(string $siteId, ?string $legalEntityId = null): bool;

    /** El centro de costo está activo, abierto a la fecha y (si se indica) es de esa empresa o compartido. */
    public function costCenterAcceptsAssignments(string $costCenterId, string $date, ?string $legalEntityId = null): bool;

    /** Igual que siteAcceptsAssignments, pero responde 422 business_rule_violated explicando el motivo. */
    public function assertSiteAssignable(string $siteId, ?string $legalEntityId = null, string $field = 'site_id'): void;

    /** Igual que costCenterAcceptsAssignments, pero responde 422 explicando el motivo. */
    public function assertCostCenterAssignable(string $costCenterId, string $date, ?string $legalEntityId = null, string $field = 'cost_center_id'): void;
}
