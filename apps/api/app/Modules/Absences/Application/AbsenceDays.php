<?php

namespace App\Modules\Absences\Application;

use App\Shared\Time\WorkingDays;
use Carbon\CarbonImmutable;

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
    public function __construct(private readonly WorkingDays $workingDays) {}

    /**
     * @param  array{legal_entity_id: string, site_id: string|null, timezone: string}  $context
     * @return array{calendar_days: int, working_days: int, hours: string|null, holidays: list<string>, starts_at: string, ends_at: string}
     */
    public function measure(array $context, string $startsOn, string $endsOn, ?string $startTime, ?string $endTime): array
    {
        $from = CarbonImmutable::parse($startsOn, $context['timezone'])->startOfDay();
        $to = CarbonImmutable::parse($endsOn, $context['timezone'])->startOfDay();
        $calendar = (int) $from->diffInDays($to) + 1;

        $count = $this->workingDays->count($context['legal_entity_id'], $context['site_id'], $startsOn, $endsOn);
        $holidays = $count['holidays'];
        $working = $count['working_days'];

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
     * Feriados entre dos fechas: los del calendario de la sede y los del calendario por defecto.
     *
     * @return list<string>
     */
    public function holidays(?string $siteId, string $from, string $to): array
    {
        return $this->workingDays->holidays($siteId, $from, $to);
    }
}
