<?php

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Infrastructure\Models\Holiday;
use App\Modules\Settings\Infrastructure\Models\HolidayCalendar;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CFG-8 · Calendarios de feriados por país, sede o empresa. Los feriados nacionales vienen del
 * paquete de país (packages/country-*); un feriado pasado no se edita ni se quita porque asistencia
 * y planilla ya lo usaron.
 */
final class HolidayCalendarService
{
    public const KINDS = ['national', 'regional', 'company', 'non_working_compensable'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Crea el calendario nacional del país si la organización aún no lo tiene y agrega los feriados
     * nacionales que falten (nuevos años publicados en el paquete de país).
     */
    public function ensureNational(string $country = 'PE'): ?HolidayCalendar
    {
        $data = $this->countryData($country);
        if ($data === null) {
            return null;
        }

        return DB::transaction(function () use ($country, $data): HolidayCalendar {
            /** @var HolidayCalendar $calendar */
            $calendar = HolidayCalendar::query()->firstOrCreate(
                ['code' => 'nacional_'.strtolower($country)],
                ['name' => (string) trans('settings.national_calendar', ['country' => $country]), 'country_code' => $country, 'is_default' => ! HolidayCalendar::query()->where('is_default', true)->exists()],
            );
            $existing = $calendar->holidays()->pluck('holiday_date')->map(fn ($d) => substr((string) $d, 0, 10))->flip();
            foreach ($data['years'] as $year) {
                foreach ($year['holidays'] as [$date, $name]) {
                    if (! $existing->has($date)) {
                        $calendar->holidays()->create(['holiday_date' => $date, 'name' => $name, 'kind' => 'national']);
                    }
                }
            }

            return $calendar;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(HolidayCalendar $c): array
    {
        $years = $this->countryData((string) $c->country_code)['years'] ?? [];

        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'country' => (string) $c->country_code,
            'scope' => ['site_id' => $c->site_id],
            'is_default' => $c->is_default,
            'holidays' => $c->holidays()->orderBy('holiday_date')->get()->map(fn (Holiday $h) => [
                'id' => $h->id,
                'date' => $h->holiday_date->toDateString(),
                'name' => $h->name,
                'kind' => $h->kind,
                'locked' => $h->holiday_date->lt(today()),
                'removable' => ! $h->holiday_date->lt(today()) && $h->kind !== 'national',
                // Feriado nacional de un año que el paquete de país aún no da por verificado.
                'to_validate' => $h->kind === 'national' && ! ($years[(int) $h->holiday_date->format('Y')]['verified'] ?? false),
            ])->all(),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{name: string, country: string, site_id?: string|null, is_default?: bool}  $input
     */
    public function create(array $input): HolidayCalendar
    {
        return DB::transaction(function () use ($input): HolidayCalendar {
            $c = HolidayCalendar::create(['code' => Str::slug($input['name'], '_').'_'.Str::lower(Str::random(4)), 'name' => $input['name'], 'country_code' => $input['country'], 'site_id' => $input['site_id'] ?? null, 'is_default' => false]);
            if ($input['is_default'] ?? false) {
                $this->makeDefault($c);
            }
            $this->audit->record('holiday_calendar.created', 'holiday_calendar', $c->id, null, ['name' => $c->name]);

            return $c;
        });
    }

    /**
     * Cambia el nombre o el calendario por defecto, y agrega o quita feriados futuros.
     *
     * @param  array{name?: string, is_default?: bool, add?: array<int, array{date: string, name: string, kind?: string}>, remove?: array<int, string>}  $input
     */
    public function update(HolidayCalendar $c, array $input): HolidayCalendar
    {
        return DB::transaction(function () use ($c, $input): HolidayCalendar {
            $before = $this->present($c);
            if (isset($input['name'])) {
                $c->forceFill(['name' => $input['name']])->save();
            }
            if (($input['is_default'] ?? false) === true) {
                $this->makeDefault($c);
            }
            foreach ($input['add'] ?? [] as $i => $h) {
                $kind = $h['kind'] ?? 'company';
                if (! in_array($kind, self::KINDS, true)) {
                    throw ValidationException::withMessages(["add.{$i}.kind" => [(string) trans('settings.invalid_value')]]);
                }
                if ($c->holidays()->whereDate('holiday_date', $h['date'])->exists()) {
                    throw new AyniException('duplicate', 409, (string) trans('settings.holiday_exists', ['date' => $h['date']]));
                }
                $c->holidays()->create(['holiday_date' => $h['date'], 'name' => $h['name'], 'kind' => $kind]);
            }
            foreach ($input['remove'] ?? [] as $id) {
                /** @var Holiday|null $h */
                $h = $c->holidays()->whereKey($id)->first();
                if ($h === null) {
                    continue;
                }
                if ($h->holiday_date->lt(today())) {
                    throw new AyniException('business_rule_violated', 422, (string) trans('settings.past_holiday_locked'));
                }
                if ($h->kind === 'national') {
                    // Los feriados nacionales los fija la ley; la organización no los quita.
                    throw new AyniException('business_rule_violated', 422, (string) trans('settings.national_holiday_locked'));
                }
                $h->delete();
            }
            $this->audit->record('holiday_calendar.updated', 'holiday_calendar', $c->id, ['holidays' => count($before['holidays'])], ['holidays' => $c->holidays()->count(), 'added' => $input['add'] ?? [], 'removed' => $input['remove'] ?? []]);

            return $c;
        });
    }

    private function makeDefault(HolidayCalendar $c): void
    {
        HolidayCalendar::query()->where('id', '!=', $c->id)->where('is_default', true)->update(['is_default' => false]);
        $c->forceFill(['is_default' => true])->save();
    }

    /**
     * @return array{source: string, years: array<int, array{verified: bool, holidays: array<int, array{0: string, 1: string}>}>}|null
     */
    private function countryData(string $country): ?array
    {
        $file = base_path('packages/country-'.strtolower($country).'/data/feriados.php');

        return is_file($file) ? require $file : null;
    }
}
