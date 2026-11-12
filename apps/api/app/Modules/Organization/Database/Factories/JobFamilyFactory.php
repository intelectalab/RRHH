<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\JobFamily;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobFamily>
 */
class JobFamilyFactory extends Factory
{
    protected $model = JobFamily::class;

    public function definition(): array
    {
        return [
            'code' => 'F'.$this->faker->unique()->numerify('####'),
            'name' => 'Familia '.$this->faker->word(),
            'status' => 'active',
        ];
    }
}
