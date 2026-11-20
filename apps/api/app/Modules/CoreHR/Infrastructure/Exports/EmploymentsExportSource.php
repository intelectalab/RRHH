<?php

namespace App\Modules\CoreHR\Infrastructure\Exports;

use App\Modules\CoreHR\Application\EmploymentPresenter;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\CustomFields\CustomFieldValues;
use App\Shared\CustomFields\HasCustomFields;
use App\Shared\Exports\ExportRegistry;
use App\Shared\Exports\ExportSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * COL-9 · Listado de colaboradores exportable: mismas columnas visibles, filtros y alcance que
 * Personas → Colaboradores. No incluye datos personales ni sueldo.
 */
final class EmploymentsExportSource implements ExportSource, HasCustomFields
{
    public function __construct(
        private readonly DataScope $scope,
        private readonly EmploymentPresenter $presenter,
        private readonly CustomFieldValues $customFields,
    ) {}

    public function customFieldEntity(): string
    {
        return 'employment';
    }

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
        // CFG-5: los filtros custom.clave y las columnas de campos personalizados, con sus permisos.
        $this->customFields->applyFilterValues($query, 'employment', 'employments.id', $filters, $permissions);
        $visible = $this->customFields->visible('employment', $permissions);
        $customKeys = $visible->pluck('key')->map(fn ($k) => (string) $k)->values()->all();
        $sensitive = $visible->filter(fn ($d) => (bool) $d->is_sensitive)->pluck('key')->map(fn ($k) => 'custom.'.$k)->values()->all();
        $employments = $query->limit($limit + 1)->get();
        $truncated = $employments->count() > $limit;
        $page = $employments->take($limit)->all();
        $custom = $customKeys === [] ? [] : $this->customFields->readMany('employment', array_map(fn (Employment $e) => (string) $e->id, $page), $permissions, 'export');
        $rows = [];
        foreach ($this->presenter->presentMany($page) as $e) {
            $row = array_map(fn ($v) => (string) ($v ?? ''), [
                $e['employee_code'], $e['person_name'], $e['legal_entity_name'], $e['job_profile_name'], $e['position_title'],
                $e['org_unit_name'], $e['site_name'], $e['manager_name'], $e['hire_date'], $e['status'],
            ]);
            foreach ($customKeys as $key) {
                $row[] = ExportRegistry::cell($custom[(string) $e['id']][$key] ?? null);
            }
            $rows[] = $row;
        }

        return [
            'columns' => array_merge(['employee_code', 'person_name', 'legal_entity_name', 'job_profile_name', 'position_title', 'org_unit_name', 'site_name', 'manager_name', 'hire_date', 'status'],
                array_map(fn ($k) => 'custom.'.$k, $customKeys)),
            'rows' => $rows, 'sensitive' => $sensitive, 'truncated' => $truncated,
        ];
    }
}
