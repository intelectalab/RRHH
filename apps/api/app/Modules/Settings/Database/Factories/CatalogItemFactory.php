<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\Catalog;
use App\Modules\Settings\Infrastructure\Models\CatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogItem>
 */
class CatalogItemFactory extends Factory
{
    protected $model = CatalogItem::class;

    public function definition(): array
    {
        $label = 'Elemento '.$this->faker->unique()->lexify('??????');

        return [
            'catalog_id' => Catalog::factory(),
            'code' => 'item_'.$this->faker->unique()->lexify('????????'),
            'label' => $label,
            'labels' => ['es' => $label],
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
