<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\JobProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobProfile>
 */
class JobProfileFactory extends Factory
{
    protected $model = JobProfile::class;

    public function definition(): array
    {
        return [
            'code' => 'P'.$this->faker->unique()->numerify('#####'),
            'name' => 'Puesto '.$this->faker->jobTitle(),
            'mission' => 'Misión del puesto',
            'is_risk_job' => false,
            'trust_classification' => 'none',
            'status' => 'active',
        ];
    }
}
