<?php

namespace App\Modules\Vacations\Infrastructure\Exports;

use App\Shared\Exports\ExportSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VAC-5 · El rol anual de vacaciones se exporta con el servicio común (UI-11): una fila por propuesta, con el
 * alcance de quien programa. No incluye datos personales ni sueldo.
 */
final class VacationScheduleExportSource implements ExportSource
{
    public function __construct(private readonly DataScope $scope) {}

    public function resource(): string
    {
        return 'vacation_schedule';
    }

    public function permission(): string
    {
        return 'time.vacation_requests.schedule';
    }

    public function filters(): array
    {
        return ['schedule_id', 'status'];
    }

    public function build(array $filters, Request $request, EffectivePermissions $permissions, int $limit): array
    {
        $query = DB::table('vacation_schedule_lines as l')
            ->join('vacation_schedules as s', 's.id', '=', 'l.vacation_schedule_id')
            ->join('employments as e', 'e.id', '=', 'l.employment_id')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->join('legal_entities as le', 'le.id', '=', 's.legal_entity_id')
            ->orderBy('s.year')->orderBy('p.paternal_surname')->orderBy('l.starts_on');
        $this->scope->applyEmployment($query, $request, $this->permission(), 'l.employment_id');
        if (($filters['schedule_id'] ?? '') !== '') {
            $query->where('l.vacation_schedule_id', $filters['schedule_id']);
        }
        if (($filters['status'] ?? '') !== '') {
            $query->whereIn('l.status', explode(',', $filters['status']));
        }
        $rows = $query->limit($limit + 1)->get(['s.year', 'le.legal_name', 'e.employee_code', 'l.starts_on', 'l.ends_on', 'l.days', 'l.status',
            DB::raw("concat_ws(' ', p.first_names, p.paternal_surname, p.maternal_surname) as person_name")]);
        $truncated = $rows->count() > $limit;

        return [
            'columns' => ['year', 'legal_entity_name', 'employee_code', 'person_name', 'starts_on', 'ends_on', 'days', 'status'],
            'rows' => $rows->take($limit)->map(fn ($r): array => [
                (string) $r->year, (string) $r->legal_name, (string) $r->employee_code, trim((string) $r->person_name),
                substr((string) $r->starts_on, 0, 10), substr((string) $r->ends_on, 0, 10),
                $r->days === null ? '' : (string) BigDecimal::of((string) $r->days)->stripTrailingZeros(),
                (string) trans('vacations.schedules.status_'.$r->status),
            ])->values()->all(),
            'sensitive' => [],
            'truncated' => $truncated,
        ];
    }
}
