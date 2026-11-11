<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Catalog>
 */
class CatalogFactory extends Factory
{
    protected $model = Catalog::class;

    public function definition(): array
    {
        return [
            'code' => 'lista_'.$this->faker->unique()->lexify('????????'),
            'name' => 'Lista '.$this->faker->unique()->lexify('??????'),
            'is_system' => false,
        ];
    }
}
