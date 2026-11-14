<?php

namespace App\Modules\Absences\Application;

use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUS-2 · Cómputo de una ausencia en el servidor (regla 11: el navegador no calcula días).
 *
 * - Días calendario: todos los días entre las dos fechas, ambas incluidas.
 * - Días hábiles: sin los días de descanso semanal (parámetro ausencias.dias_descanso_semanal, hasta que
 *   el módulo de Tiempo tenga el horario del trabajador) ni los feriados del calendario de su sede y del
 *   calendario por defecto de la organización (CFG-8).
 * - Horas: un mismo día, de la hora de inicio a la de fin.
 *
 * Los instantes (starts_at, ends_at) se calculan en la zona horaria de la sede o, sin ella, de la empresa;
 * el fin es exclusivo, así la exclusión de la base impide ausencias solapadas del mismo trabajador.
 */
final class AbsenceDays
{
    private const WEEKDAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    public function __construct(private readonly ParameterResolver $parameters) {}

    /**
     * @param  array{legal_entity_id: string, site_id: string|null, timezone: string}  $context
     * @return array{calendar_days: int, working_days: int, hours: string|null, holidays: list<string>, starts_at: string, ends_at: string}
     */
    public function measure(array $context, string $startsOn, string $endsOn, ?string $startTime, ?string $endTime): array
    {
        $from = CarbonImmutable::parse($startsOn, $context['timezone'])->startOfDay();
        $to = CarbonImmutable::parse($endsOn, $context['timezone'])->startOfDay();
        $calendar = (int) $from->diffInDays($to) + 1;

        $holidays = $this->holidays($context['site_id'], $startsOn, $endsOn);
        $rest = $this->restDays($context['legal_entity_id']);
        $working = 0;
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            if (! in_array(self::WEEKDAYS[$d->dayOfWeekIso], $rest, true) && ! in_array($d->toDateString(), $holidays, true)) {
                $working++;
            }
        }

        $hours = null;
        if ($startTime !== null && $endTime !== null) {
            $start = CarbonImmutable::parse($startsOn.' '.$startTime, $context['timezone']);
            $end = CarbonImmutable::parse($endsOn.' '.$endTime, $context['timezone']);
            $hours = number_format($start->diffInMinutes($end) / 60, 2, '.', '');
            $startsAt = $start;
            $endsAt = $end;
        } else {
            $startsAt = $from;
            $endsAt = $to->addDay();
        }

        return [
            'calendar_days' => $calendar,
            'working_days' => $working,
            'hours' => $hours,
            'holidays' => $holidays,
            'starts_at' => $startsAt->utc()->format('Y-m-d H:i:sP'),
            'ends_at' => $endsAt->utc()->format('Y-m-d H:i:sP'),
        ];
    }

    /**
     * AUS-4 · Fecha de retorno: el primer día laborable después del último día de la ausencia (sin descanso
     * semanal ni feriados de la sede). Si en las cuatro semanas siguientes no hay ninguno, el día siguiente.
     *
     * @param  array{legal_entity_id: string, site_id: string|null, timezone: string}  $context
     */
    public function returnOn(array $context, string $endsOn): string
    {
        $next = CarbonImmutable::parse($endsOn)->addDay();
        $limit = $next->addDays(27);
        $holidays = $this->holidays($context['site_id'], $next->toDateString(), $limit->toDateString());
        $rest = $this->restDays($context['legal_entity_id']);
        for ($d = $next; $d->lessThanOrEqualTo($limit); $d = $d->addDay()) {
            if (! in_array(self::WEEKDAYS[$d->dayOfWeekIso], $rest, true) && ! in_array($d->toDateString(), $holidays, true)) {
                return $d->toDateString();
            }
        }

        return $next->toDateString();
    }

    /**
     * Feriados entre dos fechas: los del calendario de la sede y los del calendario por defecto.
     *
     * @return list<string>
     */
    public function holidays(?string $siteId, string $from, string $to): array
    {
        $calendars = DB::table('holiday_calendars')->whereNull('deleted_at')->where('is_default', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($siteId !== null) {
            $own = DB::table('sites')->where('id', $siteId)->value('holiday_calendar_id');
            if ($own !== null) {
                $calendars[] = (string) $own;
            }
        }
        if ($calendars === []) {
            return [];
        }

        /** @var list<string> */
        return DB::table('holidays')->whereIn('holiday_calendar_id', array_values(array_unique($calendars)))
            ->whereBetween('holiday_date', [$from, $to])->orderBy('holiday_date')
            ->pluck('holiday_date')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all();
    }

    /**
     * @return list<string>
     */
    private function restDays(string $legalEntityId): array
    {
        $value = $this->parameters->value('ausencias.dias_descanso_semanal', $legalEntityId);

        // Sin el parámetro (no debería faltar: lo siembra el catálogo) ningún día se descuenta.
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
