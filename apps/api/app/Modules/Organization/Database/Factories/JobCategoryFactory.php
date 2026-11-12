<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\JobCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobCategory>
 */
class JobCategoryFactory extends Factory
{
    protected $model = JobCategory::class;

    public function definition(): array
    {
        return [
            'code' => 'C'.$this->faker->unique()->numerify('####'),
            'name' => 'Categoría '.$this->faker->word(),
            'functions' => 'Funciones de la categoría',
            'status' => 'active',
        ];
    }
}
