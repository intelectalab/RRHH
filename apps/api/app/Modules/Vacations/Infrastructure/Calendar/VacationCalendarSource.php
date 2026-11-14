<?php

namespace App\Modules\Vacations\Infrastructure\Calendar;

use App\Modules\Vacations\Domain\VacationRequestStateMachine;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * VAC-3 · VAC-4 · Vacaciones aprobadas (programadas, en goce o gozadas) en el calendario general (CFG-8),
 * con el alcance del usuario aplicado en la consulta (regla 6).
 */
final class VacationCalendarSource implements CalendarSource
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'vacations';
    }

    public function permission(): string
    {
        return 'time.vacation_requests.view';
    }

    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
    {
        $query = DB::table('vacation_requests as v')
            ->join('employments as e', 'e.id', '=', 'v.employment_id')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereNull('v.deleted_at')->whereIn('v.kind', ['leave', 'advance'])
            ->whereIn('v.status', VacationRequestStateMachine::APPROVED_STATES)
            ->where('v.starts_on', '<=', $to->toDateString())->where('v.ends_on', '>=', $from->toDateString());
        $this->scope->applyEmployment($query, $request, 'time.vacation_requests.view', 'v.employment_id');

        return $query->orderBy('v.starts_on')
            ->get(['v.id', 'v.starts_on', 'v.ends_on', 'v.status', 'v.days', 'p.first_names', 'p.paternal_surname'])
            ->map(fn (object $v): array => [
                'id' => (string) $v->id,
                'title' => trim($v->first_names.' '.$v->paternal_surname),
                'subtitle' => (string) trans('vacations.team.vacation_days', ['days' => rtrim(rtrim((string) $v->days, '0'), '.')]),
                'starts_on' => substr((string) $v->starts_on, 0, 10),
                'ends_on' => substr((string) $v->ends_on, 0, 10),
                'link' => '/tiempo/vacaciones?id='.$v->id,
                'tone' => (string) $v->status,
            ])->all();
    }
}
