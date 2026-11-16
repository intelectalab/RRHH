<?php

namespace App\Modules\Imports\Database\Factories;

use App\Modules\Imports\Infrastructure\Models\SetupChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SetupChecklistItem>
 */
class SetupChecklistItemFactory extends Factory
{
    protected $model = SetupChecklistItem::class;

    public function definition(): array
    {
        return [
            'code' => 'paso_'.$this->faker->unique()->lexify('????????'),
            'title' => $this->faker->sentence(3),
            'module_code' => 'core',
            'status' => 'skipped',
            'sort_order' => 1,
        ];
    }
}
