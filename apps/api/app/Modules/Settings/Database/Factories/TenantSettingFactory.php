<?php

namespace App\Modules\Settings\Database\Factories;

use App\Modules\Settings\Infrastructure\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    protected $model = TenantSetting::class;

    public function definition(): array
    {
        return [
            'parameter_code' => 'org.zona_horaria',
            'scope_kind' => 'organization',
            'value' => 'America/Bogota',
            'valid_from' => now()->subDays($this->faker->unique()->numberBetween(1, 3000))->toDateString(),
        ];
    }
}
