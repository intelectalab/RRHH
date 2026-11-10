<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeRate>
 */
class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'from_currency' => 'USD', 'to_currency' => 'PEN',
            'rate_date' => now()->subDays($this->faker->unique()->numberBetween(1, 3000))->toDateString(),
            'rate' => '3.75000000', 'source' => 'Prueba',
        ];
    }
}
