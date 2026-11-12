<?php

namespace App\Shared\Organization;

/**
 * ORG-1 · ORG-2 · ORG-3 · ORG-4 · ORG-5 · Contrato común para los módulos que asignan personas a la estructura
 * (relaciones laborales, asignaciones, posiciones, gastos, hojas de tiempo): una empresa desactivada,
 * una unidad archivada, una sede archivada, un centro de costo cerrado o archivado y un puesto de catálogo archivado no
 * admiten nuevas asignaciones. Lo implementa el módulo de Organización.
 */
interface StructureAvailability
{
    /** ORG-1: la empresa existe en la organización y está activa (una desactivada no admite nuevas relaciones laborales). */
    public function legalEntityAcceptsEmployments(string $legalEntityId): bool;

    /** Igual que legalEntityAcceptsEmployments, pero responde 422 explicando el motivo. */
    public function assertLegalEntityAssignable(string $legalEntityId, string $field = 'legal_entity_id'): void;

    /** ORG-5 · RN-ORG-04: el puesto de catálogo existe y está activo (uno archivado no admite nuevas posiciones). */
    public function jobProfileAcceptsPositions(string $jobProfileId): bool;

    /** Igual que jobProfileAcceptsPositions, pero responde 422 explicando el motivo. */
    public function assertJobProfileAssignable(string $jobProfileId, string $field = 'job_profile_id'): void;

    /** ORG-3 · POS-1: la unidad existe y está activa (una archivada no admite nuevas posiciones). */
    public function orgUnitAcceptsPositions(string $orgUnitId): bool;

    /** Igual que orgUnitAcceptsPositions, pero responde 422 explicando el motivo. */
    public function assertOrgUnitAssignable(string $orgUnitId, string $field = 'org_unit_id'): void;

    /** La sede existe en la organización, está activa y (si se indica) pertenece a esa empresa o es compartida. */
    public function siteAcceptsAssignments(string $siteId, ?string $legalEntityId = null): bool;

    /** El centro de costo está activo, abierto a la fecha y (si se indica) es de esa empresa o compartido. */
    public function costCenterAcceptsAssignments(string $costCenterId, string $date, ?string $legalEntityId = null): bool;

    /** Igual que siteAcceptsAssignments, pero responde 422 business_rule_violated explicando el motivo. */
    public function assertSiteAssignable(string $siteId, ?string $legalEntityId = null, string $field = 'site_id'): void;

    /** Igual que costCenterAcceptsAssignments, pero responde 422 explicando el motivo. */
    public function assertCostCenterAssignable(string $costCenterId, string $date, ?string $legalEntityId = null, string $field = 'cost_center_id'): void;
}
