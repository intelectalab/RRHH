<?php

namespace App\Modules\Contracts\Database\Factories;

use App\Modules\Contracts\Infrastructure\Models\ContractType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContractType>
 */
class ContractTypeFactory extends Factory
{
    protected $model = ContractType::class;

    public function definition(): array
    {
        return [
            'code' => 'propio_'.Str::lower(Str::random(8)),
            'name' => 'Tipo '.$this->faker->word(),
            'country_code' => 'PE',
            'category' => 'other',
            'requires_objective_cause' => false,
            'requires_end_date' => false,
            'is_system' => false,
        ];
    }
}
