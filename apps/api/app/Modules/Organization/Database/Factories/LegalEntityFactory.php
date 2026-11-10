<?php

namespace App\Modules\Organization\Database\Factories;

use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalEntity>
 */
class LegalEntityFactory extends Factory
{
    protected $model = LegalEntity::class;

    public function definition(): array
    {
        return [
            'legal_name' => $this->faker->company().' S.A.C.',
            'country_code' => 'PE',
            'tax_id' => '20'.$this->faker->unique()->numerify('#########'),
            'currency' => 'PEN',
            'timezone' => 'America/Lima',
            'status' => 'active',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (LegalEntity $entity): void {
            $entity->regimes()->create(['labor_regime_code' => 'pe_general', 'valid_from' => now()->toDateString()]);
        });
    }
}
