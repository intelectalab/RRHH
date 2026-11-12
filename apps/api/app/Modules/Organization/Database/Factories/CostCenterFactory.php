<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\CostCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCenter>
 */
class CostCenterFactory extends Factory
{
    protected $model = CostCenter::class;

    public function definition(): array
    {
        return [
            'code' => 'CC'.$this->faker->unique()->numerify('#####'),
            'name' => 'Centro '.$this->faker->word(),
            'status' => 'active',
        ];
    }
}
