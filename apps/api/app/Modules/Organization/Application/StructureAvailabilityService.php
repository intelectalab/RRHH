<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Modules\Organization\Infrastructure\Models\JobProfile;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Validation\ValidationException;

/**
 * ORG-1 · ORG-2 · ORG-3 · ORG-4 · ORG-5 · Empresas, unidades, sedes, centros de costo y puestos que admiten nuevas asignaciones.
 */
final class StructureAvailabilityService implements StructureAvailability
{
    public function legalEntityAcceptsEmployments(string $legalEntityId): bool
    {
        return $this->legalEntityProblem($legalEntityId) === null;
    }

    public function assertLegalEntityAssignable(string $legalEntityId, string $field = 'legal_entity_id'): void
    {
        $problem = $this->legalEntityProblem($legalEntityId);
        if ($problem !== null) {
            throw ValidationException::withMessages([$field => [(string) trans('organization.'.$problem)]]);
        }
    }

    public function jobProfileAcceptsPositions(string $jobProfileId): bool
    {
        return $this->jobProfileProblem($jobProfileId) === null;
    }

    public function assertJobProfileAssignable(string $jobProfileId, string $field = 'job_profile_id'): void
    {
        $problem = $this->jobProfileProblem($jobProfileId);
        if ($problem !== null) {
            throw ValidationException::withMessages([$field => [(string) trans('organization.jobs.'.$problem)]]);
        }
    }

    public function orgUnitAcceptsPositions(string $orgUnitId): bool
    {
        return $this->orgUnitProblem($orgUnitId) === null;
    }

    public function assertOrgUnitAssignable(string $orgUnitId, string $field = 'org_unit_id'): void
    {
        $problem = $this->orgUnitProblem($orgUnitId);
        if ($problem !== null) {
            throw ValidationException::withMessages([$field => [(string) trans('organization.units.'.$problem)]]);
        }
    }

    private function orgUnitProblem(string $orgUnitId): ?string
    {
        $status = OrgUnit::query()->whereKey($orgUnitId)->value('status');
        if (! is_string($status)) {
            return 'not_found';
        }

        return $status === 'active' ? null : 'archived_no_positions';
    }

    private function legalEntityProblem(string $legalEntityId): ?string
    {
        $status = LegalEntity::query()->whereKey($legalEntityId)->value('status');
        if (! is_string($status)) {
            return 'legal_entity_not_found';
        }

        return $status === 'active' ? null : 'legal_entity_inactive';
    }

    private function jobProfileProblem(string $jobProfileId): ?string
    {
        $status = JobProfile::query()->whereKey($jobProfileId)->value('status');
        if (! is_string($status)) {
            return 'not_found';
        }

        return $status === 'inactive' ? 'archived_no_positions' : null;
    }

    public function siteAcceptsAssignments(string $siteId, ?string $legalEntityId = null): bool
    {
        return $this->siteProblem($siteId, $legalEntityId) === null;
    }

    public function costCenterAcceptsAssignments(string $costCenterId, string $date, ?string $legalEntityId = null): bool
    {
        return $this->costCenterProblem($costCenterId, $date, $legalEntityId) === null;
    }

    public function assertSiteAssignable(string $siteId, ?string $legalEntityId = null, string $field = 'site_id'): void
    {
        $problem = $this->siteProblem($siteId, $legalEntityId);
        if ($problem !== null) {
            throw ValidationException::withMessages([$field => [(string) trans('organization.sites.'.$problem)]]);
        }
    }

    public function assertCostCenterAssignable(string $costCenterId, string $date, ?string $legalEntityId = null, string $field = 'cost_center_id'): void
    {
        $problem = $this->costCenterProblem($costCenterId, $date, $legalEntityId);
        if ($problem !== null) {
            throw ValidationException::withMessages([$field => [(string) trans('organization.cost_centers.'.$problem)]]);
        }
    }

    private function siteProblem(string $siteId, ?string $legalEntityId): ?string
    {
        $site = Site::query()->find($siteId);
        if (! $site instanceof Site) {
            return 'not_found';
        }
        if ($site->status !== 'active') {
            return 'archived_no_assignments';
        }
        if ($legalEntityId !== null && $site->legal_entity_id !== null && $site->legal_entity_id !== $legalEntityId) {
            return 'other_company';
        }

        return null;
    }

    private function costCenterProblem(string $costCenterId, string $date, ?string $legalEntityId): ?string
    {
        $center = CostCenter::query()->find($costCenterId);
        if (! $center instanceof CostCenter) {
            return 'not_found';
        }
        if ($center->status === 'inactive') {
            return 'archived_no_assignments';
        }
        if ($center->status === 'closed') {
            return 'closed_no_assignments';
        }
        $day = EffectiveDated::day($date);
        if ($center->opened_on !== null && $center->opened_on->toDateString() > $day) {
            return 'not_open_yet';
        }
        if ($legalEntityId !== null && $center->legal_entity_id !== null && $center->legal_entity_id !== $legalEntityId) {
            return 'other_company';
        }

        return null;
    }
}
