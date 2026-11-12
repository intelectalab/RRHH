<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Validation\ValidationException;

/**
 * ORG-2 · ORG-4 · Sedes y centros de costo que admiten nuevas asignaciones.
 */
final class StructureAvailabilityService implements StructureAvailability
{
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
