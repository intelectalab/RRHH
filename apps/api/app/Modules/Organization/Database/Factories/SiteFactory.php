<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'code' => 'S'.$this->faker->unique()->numerify('#####'),
            'name' => 'Sede '.$this->faker->city(),
            'kind' => 'branch',
            'country_code' => 'PE',
            'is_risk_site' => false,
            'status' => 'active',
        ];
    }
}
