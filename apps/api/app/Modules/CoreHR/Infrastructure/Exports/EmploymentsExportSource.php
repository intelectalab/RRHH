<?php

namespace App\Modules\CoreHR\Infrastructure\Exports;

use App\Modules\CoreHR\Application\EmploymentPresenter;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Exports\ExportSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * COL-9 · Listado de colaboradores exportable: mismas columnas visibles, filtros y alcance que
 * Personas → Colaboradores. No incluye datos personales ni sueldo.
 */
final class EmploymentsExportSource implements ExportSource
{
    public function __construct(
        private readonly DataScope $scope,
        private readonly EmploymentPresenter $presenter,
    ) {}

    public function resource(): string
    {
        return 'employments';
    }

    public function permission(): string
    {
        return 'people.employments.view';
    }

    public function filters(): array
    {
        return ['q', 'status', 'legal_entity_id', 'site_id', 'org_unit_id', 'job_profile_id'];
    }

    public function build(array $filters, Request $request, EffectivePermissions $permissions, int $limit): array
    {
        $query = Employment::query()->join('people as p', 'p.id', '=', 'employments.person_id')
            ->leftJoin(DB::raw('('.EmploymentPresenter::CURRENT_ASSIGNMENT_SQL.') as ca'), 'ca.employment_id', '=', 'employments.id')
            ->select('employments.*')->orderBy('p.paternal_surname')->orderBy('employments.id');
        $this->scope->applyEmployment($query, $request, $this->permission(), 'employments.id');
        if (($filters['q'] ?? '') !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($w) => $w->whereRaw("concat_ws(' ', p.first_names, p.preferred_name, p.paternal_surname, p.maternal_surname) ilike ?", [$like])
                ->orWhere('employments.employee_code', 'ilike', $like));
        }
        if (($filters['status'] ?? '') !== '') {
            $query->whereIn('employments.status', explode(',', $filters['status']));
        }
        if (($filters['legal_entity_id'] ?? '') !== '') {
            $query->where('employments.legal_entity_id', $filters['legal_entity_id']);
        }
        foreach (['site_id', 'org_unit_id', 'job_profile_id'] as $f) {
            if (($filters[$f] ?? '') !== '') {
                $query->where('ca.'.$f, $filters[$f]);
            }
        }
        $employments = $query->limit($limit + 1)->get();
        $truncated = $employments->count() > $limit;
        $rows = [];
        foreach ($this->presenter->presentMany($employments->take($limit)->all()) as $e) {
            $rows[] = array_map(fn ($v) => (string) ($v ?? ''), [
                $e['employee_code'], $e['person_name'], $e['legal_entity_name'], $e['job_profile_name'], $e['position_title'],
                $e['org_unit_name'], $e['site_name'], $e['manager_name'], $e['hire_date'], $e['status'],
            ]);
        }

        return [
            'columns' => ['employee_code', 'person_name', 'legal_entity_name', 'job_profile_name', 'position_title', 'org_unit_name', 'site_name', 'manager_name', 'hire_date', 'status'],
            'rows' => $rows, 'sensitive' => [], 'truncated' => $truncated,
        ];
    }
}
