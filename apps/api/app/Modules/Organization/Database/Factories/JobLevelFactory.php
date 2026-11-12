<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\JobLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobLevel>
 */
class JobLevelFactory extends Factory
{
    protected $model = JobLevel::class;

    public function definition(): array
    {
        return [
            'code' => 'N'.$this->faker->unique()->numerify('####'),
            'name' => 'Nivel '.$this->faker->word(),
            'rank' => $this->faker->numberBetween(1, 20),
            'status' => 'active',
        ];
    }
}
