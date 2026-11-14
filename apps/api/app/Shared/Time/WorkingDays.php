<?php

namespace App\Shared\Time;

use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUS-2 · VAC-3 · Cómputo común de días en el servidor (regla 11): días calendario, días hábiles y
 * feriados entre dos fechas laborales.
 *
 * - Días hábiles: sin los días de descanso semanal (parámetro ausencias.dias_descanso_semanal, hasta que
 *   el módulo de Tiempo tenga el horario del trabajador) ni los feriados del calendario de su sede y de
 *   los calendarios por defecto de la organización (CFG-8).
 */
final class WorkingDays
{
    private const WEEKDAYS = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

    public function __construct(private readonly ParameterResolver $parameters) {}

    /**
     * @return array{calendar_days: int, working_days: int, holidays: list<string>}
     */
    public function count(string $legalEntityId, ?string $siteId, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $holidays = $this->holidays($siteId, $from, $to);
        $rest = $this->restDays($legalEntityId);
        $working = 0;
        for ($d = $start; $d->lessThanOrEqualTo($end); $d = $d->addDay()) {
            if (! in_array(self::WEEKDAYS[$d->dayOfWeekIso], $rest, true) && ! in_array($d->toDateString(), $holidays, true)) {
                $working++;
            }
        }

        return ['calendar_days' => (int) $start->diffInDays($end) + 1, 'working_days' => $working, 'holidays' => $holidays];
    }

    /**
     * Feriados entre dos fechas: los del calendario de la sede y los de los calendarios por defecto.
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
     * Días de descanso semanal de la empresa (lunes a domingo, en inglés).
     *
     * @return list<string>
     */
    public function restDays(string $legalEntityId): array
    {
        $value = $this->parameters->value('ausencias.dias_descanso_semanal', $legalEntityId);

        // Sin el parámetro (no debería faltar: lo siembra el catálogo) ningún día se descuenta.
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** Día de la semana de una fecha, como en restDays(). */
    public static function weekday(CarbonImmutable $day): string
    {
        return self::WEEKDAYS[$day->dayOfWeekIso];
    }
}
