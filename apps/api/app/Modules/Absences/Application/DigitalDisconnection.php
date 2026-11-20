<?php

namespace App\Modules\Absences\Application;

use App\Shared\Labor\RemoteWorkAgreements;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\SelfService\OwnEmployments;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Time\ApprovedAbsences;
use App\Shared\Time\DisconnectionWindow;
use App\Shared\Time\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUS-5 · Desconexión digital del teletrabajador (Ley 31572). Un usuario está en teletrabajo hoy si tiene un
 * acuerdo vigente (CON-6) o un «trabajo remoto» aprobado para el día. Fuera de su jornada, los avisos no
 * urgentes se difieren al inicio de la siguiente jornada, y nunca antes de cumplir la desconexión mínima: la
 * mayor entre la del acuerdo y el parámetro legal teletrabajo_desconexion_horas, contada desde el fin de la
 * jornada.
 *
 * La jornada sale de los parámetros jornada.hora_inicio y jornada.hora_fin (por empresa o sede) hasta que el
 * módulo de turnos asigne un horario por trabajador; sin ellos no hay cómo saber cuándo termina la jornada y el
 * aviso sale sin diferir. Los días de descanso y feriados son desconexión completa.
 */
final class DigitalDisconnection implements DisconnectionWindow
{
    private const MAX_DAYS_AHEAD = 14;

    public function __construct(
        private readonly OwnEmployments $employments,
        private readonly RemoteWorkAgreements $remoteWork,
        private readonly ApprovedAbsences $absences,
        private readonly WorkingDays $workingDays,
        private readonly ParameterResolver $parameters,
        private readonly LegalParameters $legal,
    ) {}

    public function resumeAt(string $userId, CarbonImmutable $now): ?CarbonImmutable
    {
        $resume = null;
        foreach ($this->employments->list($userId) as $employment) {
            if ($employment['read_only'] || $employment['status'] === 'terminated') {
                continue;
            }
            $candidate = $this->forEmployment($employment['id'], $employment['legal_entity_id'], $now);
            if ($candidate !== null && ($resume === null || $candidate->greaterThan($resume))) {
                $resume = $candidate;
            }
        }

        return $resume;
    }

    private function forEmployment(string $employmentId, string $legalEntityId, CarbonImmutable $now): ?CarbonImmutable
    {
        $entity = DB::table('legal_entities')->where('id', $legalEntityId)->first(['timezone', 'country_code']);
        $tz = (string) ($entity->timezone ?? 'UTC');
        $local = $now->setTimezone($tz);
        $today = $local->toDateString();

        $agreement = $this->remoteWork->activeOn($employmentId, $today);
        if ($agreement === null && ! $this->remoteToday($employmentId, $today)) {
            return null;
        }
        $start = $this->parameters->value('jornada.hora_inicio', $legalEntityId);
        $end = $this->parameters->value('jornada.hora_fin', $legalEntityId);
        if (! is_string($start) || ! is_string($end) || preg_match('/^\d{2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{2}:\d{2}$/', $end) !== 1 || $start >= $end) {
            return null;
        }

        $site = DB::table('employment_assignments')->where('employment_id', $employmentId)->whereNull('deleted_at')
            ->where('valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
            ->value('site_id');
        $rest = $this->workingDays->restDays($legalEntityId);
        $holidays = $this->workingDays->holidays($site === null ? null : (string) $site, $today, $local->addDays(self::MAX_DAYS_AHEAD)->toDateString());
        $isWorkday = fn (CarbonImmutable $d): bool => ! in_array(WorkingDays::weekday($d), $rest, true) && ! in_array($d->toDateString(), $holidays, true);

        $todayStart = $local->setTimeFromTimeString($start);
        $todayEnd = $local->setTimeFromTimeString($end);
        if ($isWorkday($local) && $local->greaterThanOrEqualTo($todayStart) && $local->lessThan($todayEnd)) {
            return null; // Dentro de la jornada: el aviso sale ya.
        }

        // Siguiente inicio de jornada (hoy si aún no empieza).
        $next = null;
        for ($i = 0; $i <= self::MAX_DAYS_AHEAD; $i++) {
            $day = $local->addDays($i)->startOfDay();
            $dayStart = $day->setTimeFromTimeString($start);
            if ($isWorkday($day) && $dayStart->greaterThan($local)) {
                $next = $dayStart;
                break;
            }
        }
        if ($next === null) {
            return null;
        }

        // La desconexión mínima se cuenta desde el último fin de jornada.
        $hours = max($agreement['disconnection_hours'] ?? 0, $this->legalMinimum((string) ($entity->country_code ?? 'PE')));
        if ($hours > 0) {
            $lastEnd = $local->greaterThanOrEqualTo($todayEnd) ? $todayEnd : $todayEnd->subDay();
            $earliest = $lastEnd->addHours($hours);
            if ($earliest->greaterThan($next)) {
                $next = $earliest;
            }
        }

        return $next->utc();
    }

    private function remoteToday(string $employmentId, string $date): bool
    {
        foreach ($this->absences->on($employmentId, $date) as $absence) {
            // RN-AUS-09: el trabajo remoto aprobado no afecta asistencia; es el día en teletrabajo.
            if ($this->isRemoteType($absence['absence_type_id'])) {
                return true;
            }
        }

        return false;
    }

    private function isRemoteType(string $typeId): bool
    {
        return DB::table('absence_types')->where('id', $typeId)->value('category') === 'remote_work';
    }

    private function legalMinimum(string $country): int
    {
        $p = $this->legal->at($country, 'teletrabajo_desconexion_horas');

        return is_numeric($p['value'] ?? null) ? (int) $p['value'] : 0;
    }
}
