<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\HealthProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * EPS con la que trabaja la organización.
 *
 * @extends Factory<HealthProvider>
 */
class HealthProviderFactory extends Factory
{
    protected $model = HealthProvider::class;

    public function definition(): array
    {
        return [
            'code' => 'eps_'.$this->faker->unique()->numerify('####'),
            'name' => 'EPS '.$this->faker->lastName(),
            'kind' => 'eps',
        ];
    }
}
