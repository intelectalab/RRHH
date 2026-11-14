<?php

namespace App\Modules\Absences\Infrastructure\Time;

use App\Modules\Absences\Application\AbsenceTypeCatalog;
use App\Modules\Absences\Domain\AbsenceRequestStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\Time\ApprovedAbsences;

/**
 * AUS-2 · Implementación del puerto ApprovedAbsences: asistencia consulta aquí si una fecha está justificada.
 * RN-AUS-09: el trabajo remoto ocasional (affects_attendance = false) no justifica ausencia: se marca remoto.
 */
final class ApprovedAbsencesQuery implements ApprovedAbsences
{
    public function __construct(private readonly AbsenceTypeCatalog $types) {}

    public function on(string $employmentId, string $date): array
    {
        $out = [];
        $rows = AbsenceRequest::query()->where('employment_id', $employmentId)
            ->whereIn('status', AbsenceRequestStateMachine::APPROVED_STATES)
            ->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->orderBy('starts_at')->get();
        foreach ($rows as $a) {
            $type = AbsenceType::query()->withTrashed()->find($a->absence_type_id);
            if (! $type instanceof AbsenceType) {
                continue;
            }
            $rule = $this->types->rulesAt($type, $a->day('starts_on'));
            $out[] = [
                'id' => $a->id,
                'absence_type_id' => $type->id,
                'absence_type_code' => $type->code,
                'unit' => $rule->unit ?? 'days',
                'starts_on' => $a->day('starts_on'),
                'ends_on' => $a->day('ends_on'),
                'start_time' => $a->start_time === null ? null : substr($a->start_time, 0, 5),
                'end_time' => $a->end_time === null ? null : substr($a->end_time, 0, 5),
                'is_paid' => (bool) ($rule->is_paid ?? true),
                'affects_attendance' => (bool) ($rule->affects_attendance ?? true),
            ];
        }

        return $out;
    }

    public function justifiesFullDay(string $employmentId, string $date): bool
    {
        foreach ($this->on($employmentId, $date) as $absence) {
            if ($absence['affects_attendance'] && $absence['unit'] === 'days') {
                return true;
            }
        }

        return false;
    }
}
