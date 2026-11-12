<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\OrgUnitType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgUnitType>
 */
class OrgUnitTypeFactory extends Factory
{
    protected $model = OrgUnitType::class;

    public function definition(): array
    {
        return [
            'code' => 'nivel_'.$this->faker->unique()->numerify('####'),
            'name' => 'Nivel '.$this->faker->unique()->word(),
            'rank' => 10,
        ];
    }
}
