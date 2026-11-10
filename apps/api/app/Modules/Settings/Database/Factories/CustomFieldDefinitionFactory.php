<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\CustomFieldDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomFieldDefinition>
 */
class CustomFieldDefinitionFactory extends Factory
{
    protected $model = CustomFieldDefinition::class;

    public function definition(): array
    {
        return ['entity_type' => 'legal_entity', 'key' => 'campo_'.$this->faker->unique()->numberBetween(1, 99999), 'label' => 'Campo '.$this->faker->word(), 'data_type' => 'text'];
    }
}
