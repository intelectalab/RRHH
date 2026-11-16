<?php

namespace App\Modules\Imports\Database\Factories;

use App\Modules\Imports\Infrastructure\Models\TenantCountryPack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantCountryPack>
 */
class TenantCountryPackFactory extends Factory
{
    protected $model = TenantCountryPack::class;

    public function definition(): array
    {
        return [
            'country_pack_code' => 'pe-base',
            'installed_version' => '1.0.0',
            'installed_at' => now(),
            'status' => 'active',
        ];
    }
}
