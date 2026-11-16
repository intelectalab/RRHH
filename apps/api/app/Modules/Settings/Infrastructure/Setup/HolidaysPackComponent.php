<?php

namespace App\Modules\Settings\Infrastructure\Setup;

use App\Modules\Settings\Application\HolidayCalendarService;
use App\Shared\Setup\CountryPackComponent;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Calendario nacional de feriados del paquete de país (CFG-8). La organización agrega feriados
 * propios o regionales y calendarios por sede en Configuración → Feriados.
 */
final class HolidaysPackComponent implements CountryPackComponent
{
    public function __construct(private readonly HolidayCalendarService $calendars) {}

    public function code(): string
    {
        return 'holidays';
    }

    public function moduleCode(): string
    {
        return 'base';
    }

    public function items(string $country): array
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/feriados.php');
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || ! is_file($file)) {
            return [];
        }
        /** @var array{years: array<int, array{holidays: list<array{0: string, 1: string}>}>} $data */
        $data = require $file;
        $items = [];
        foreach ($data['years'] as $year) {
            foreach ($year['holidays'] as [$date, $name]) {
                $items[] = $date.' · '.$name;
            }
        }

        return $items;
    }

    public function present(string $country): int
    {
        return DB::table('holiday_calendars as c')->join('holidays as h', 'h.holiday_calendar_id', '=', 'c.id')
            ->where('c.code', 'nacional_'.strtolower($country))->count();
    }

    public function apply(string $country, ?string $userId): void
    {
        $this->calendars->ensureNational($country);
    }

    public function link(): string
    {
        return '/configuracion/feriados';
    }
}
