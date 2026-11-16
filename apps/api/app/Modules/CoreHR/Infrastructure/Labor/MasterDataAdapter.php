<?php

namespace App\Modules\CoreHR\Infrastructure\Labor;

use App\Shared\Labor\MasterData;
use Illuminate\Support\Facades\DB;

/**
 * HIS-6 · Lectura de la asignación (HIS-2) y la compensación (HIS-3) con vigencia a una fecha. Es la única
 * implementación de MasterData: se lee de las tablas fuente, sin copias ni caché que pueda quedar vieja.
 */
final class MasterDataAdapter implements MasterData
{
    private const CURRENT = 'valid_from <= ?::date AND (valid_to IS NULL OR valid_to >= ?::date)';

    public function at(string $employmentId, string $asOf): ?array
    {
        return $this->many([$employmentId], $asOf)[$employmentId] ?? null;
    }

    public function many(array $employmentIds, string $asOf): array
    {
        $ids = array_values(array_unique(array_filter($employmentIds, fn (string $id): bool => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1)));
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('employment_assignments as a')
            ->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->leftJoin('positions as p', 'p.id', '=', 'a.position_id')
            ->whereIn('a.employment_id', $ids)
            ->whereRaw('a.valid_from <= ?::date AND (a.valid_to IS NULL OR a.valid_to >= ?::date)', [$asOf, $asOf])
            ->get(['a.employment_id', 'e.legal_entity_id', 'a.job_profile_id', 'a.position_id', 'a.org_unit_id', 'a.site_id', 'a.cost_center_id',
                'a.manager_employment_id', 'a.work_modality', 'a.valid_from', 'a.valid_to', 'p.reports_to_position_id']);

        $out = [];
        foreach ($rows as $r) {
            $manager = $r->manager_employment_id === null ? null : (string) $r->manager_employment_id;
            $source = $manager === null ? null : 'assignment';
            if ($manager === null && $r->reports_to_position_id !== null) {
                $manager = $this->occupant((string) $r->reports_to_position_id, (string) $r->employment_id, $asOf);
                $source = $manager === null ? null : 'position';
            }
            $id = (string) $r->employment_id;
            $out[$id] = [
                'employment_id' => $id,
                'as_of' => $asOf,
                'legal_entity_id' => (string) $r->legal_entity_id,
                'job_profile_id' => (string) $r->job_profile_id,
                'position_id' => $r->position_id === null ? null : (string) $r->position_id,
                'org_unit_id' => (string) $r->org_unit_id,
                'site_id' => (string) $r->site_id,
                'cost_center_id' => $r->cost_center_id === null ? null : (string) $r->cost_center_id,
                'manager_employment_id' => $manager,
                'manager_source' => $source,
                'work_modality' => (string) $r->work_modality,
                'valid_from' => substr((string) $r->valid_from, 0, 10),
                'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10),
            ];
        }

        return $out;
    }

    public function managerAt(string $employmentId, string $asOf): ?string
    {
        return $this->at($employmentId, $asOf)['manager_employment_id'] ?? null;
    }

    public function salaryAt(string $employmentId, string $asOf): ?array
    {
        $row = DB::table('employment_compensations')->where('employment_id', $employmentId)
            ->whereRaw(self::CURRENT, [$asOf, $asOf])->orderByDesc('valid_from')
            ->first(['id', 'base_salary', 'currency', 'pay_frequency', 'salary_basis', 'valid_from', 'valid_to']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'amount' => (string) $row->base_salary,
            'currency' => (string) $row->currency,
            'pay_frequency' => (string) $row->pay_frequency,
            'salary_basis' => (string) $row->salary_basis,
            'valid_from' => substr((string) $row->valid_from, 0, 10),
            'valid_to' => $row->valid_to === null ? null : substr((string) $row->valid_to, 0, 10),
        ];
    }

    /** Quien ocupa la posición jefe a la fecha (si hay varios, el más antiguo en ella; nunca la propia persona). */
    private function occupant(string $positionId, string $self, string $asOf): ?string
    {
        $id = DB::table('employment_assignments')->where('position_id', $positionId)->where('employment_id', '<>', $self)
            ->whereRaw(self::CURRENT, [$asOf, $asOf])->orderBy('valid_from')->orderBy('employment_id')->value('employment_id');

        return $id === null ? null : (string) $id;
    }
}
