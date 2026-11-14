<?php

namespace App\Modules\Absences\Infrastructure\Calendar;

use App\Modules\Absences\Domain\AbsenceRequestStateMachine;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AUS-2 · Ausencias aprobadas en el calendario del equipo, con el alcance del usuario en la consulta
 * (regla 6). RN-AUS-05: un tipo confidencial se muestra como «Ausencia justificada» a quien no tiene
 * permiso de ver certificados médicos.
 */
final class AbsenceCalendarSource implements CalendarSource
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'absences';
    }

    public function permission(): string
    {
        return 'time.absences.view';
    }

    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
    {
        // Consulta directa (con RLS de la organización): el modelo agregaría su ámbito sobre el nombre sin alias.
        $query = DB::table('absence_requests as r')
            ->join('absence_types as t', 't.id', '=', 'r.absence_type_id')
            ->join('employments as e', 'e.id', '=', 'r.employment_id')
            ->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereNull('r.deleted_at')
            ->whereIn('r.status', AbsenceRequestStateMachine::APPROVED_STATES)
            ->where('r.starts_on', '<=', $to->toDateString())->where('r.ends_on', '>=', $from->toDateString());
        $this->scope->applyEmployment($query, $request, 'time.absences.view', 'r.employment_id');
        $medical = $permissions?->has('time.absences.medical_certificate.view') ?? false;
        $viewerPerson = $request->user() === null ? null : DB::table('users')->where('id', $request->user()->getAuthIdentifier())->value('person_id');

        return $query->orderBy('r.starts_on')
            ->get(['r.id', 'r.starts_on', 'r.ends_on', 'r.start_time', 'r.end_time', 'r.status', 't.name as type_name', 't.is_confidential',
                'e.person_id', 'p.first_names', 'p.paternal_surname'])
            ->map(function (object $r) use ($medical, $viewerPerson): array {
                $confidential = (bool) $r->is_confidential && ! $medical && $r->person_id !== $viewerPerson;
                $type = $confidential ? (string) trans('absences.requests.justified') : (string) $r->type_name;
                $hours = $r->start_time === null ? null : substr((string) $r->start_time, 0, 5).'–'.substr((string) $r->end_time, 0, 5);

                return [
                    'id' => (string) $r->id,
                    'title' => trim($r->first_names.' '.$r->paternal_surname),
                    'subtitle' => $hours === null ? $type : $type.' · '.$hours,
                    'starts_on' => substr((string) $r->starts_on, 0, 10),
                    'ends_on' => substr((string) $r->ends_on, 0, 10),
                    'link' => '/tiempo/ausencias?id='.$r->id,
                    'tone' => (string) $r->status,
                ];
            })->all();
    }
}
