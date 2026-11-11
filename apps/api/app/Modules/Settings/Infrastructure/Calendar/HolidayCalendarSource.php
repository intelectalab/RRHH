<?php

namespace App\Modules\Settings\Infrastructure\Calendar;

use App\Modules\Settings\Application\HolidayCalendarService;
use App\Modules\Settings\Infrastructure\Models\Holiday;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * CFG-8 · Feriados de los calendarios de la organización (nacional precargado y propios) en el
 * calendario central. Los ve todo el que ve el calendario.
 */
final class HolidayCalendarSource implements CalendarSource
{
    public function __construct(private readonly HolidayCalendarService $calendars) {}

    public function key(): string
    {
        return 'holidays';
    }

    public function permission(): string
    {
        return 'time.calendar.view';
    }

    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
    {
        $this->calendars->ensureNational();

        return Holiday::query()
            ->join('holiday_calendars as c', 'c.id', '=', 'holidays.holiday_calendar_id')
            ->whereBetween('holidays.holiday_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('holidays.holiday_date')->orderBy('c.name')
            ->get(['holidays.id', 'holidays.holiday_date', 'holidays.name', 'holidays.kind', 'c.id as calendar_id', 'c.name as calendar_name'])
            ->map(function (Holiday $h): array {
                $date = $h->holiday_date->toDateString();

                return [
                    'id' => $h->id,
                    'title' => $h->name,
                    'subtitle' => (string) $h->getAttribute('calendar_name'),
                    'starts_on' => $date,
                    'ends_on' => $date,
                    'link' => '/configuracion/feriados?calendario='.$h->getAttribute('calendar_id').'&anio='.substr($date, 0, 4),
                    'tone' => $h->kind,
                ];
            })->all();
    }
}
