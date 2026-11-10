<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\HolidayCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HolidayCalendar>
 */
class HolidayCalendarFactory extends Factory
{
    protected $model = HolidayCalendar::class;

    public function definition(): array
    {
        return ['code' => 'cal_'.Str::lower(Str::random(8)), 'name' => 'Calendario '.$this->faker->word(), 'country_code' => 'PE', 'is_default' => false];
    }
}
