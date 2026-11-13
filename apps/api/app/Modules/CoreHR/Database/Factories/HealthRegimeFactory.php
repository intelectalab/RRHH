<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\HealthRegime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Régimen general con EsSalud, vigente desde el ingreso.
 *
 * @extends Factory<HealthRegime>
 */
class HealthRegimeFactory extends Factory
{
    protected $model = HealthRegime::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'labor_regime_code' => 'pe_general',
            'health_scheme' => 'essalud',
            'valid_from' => '2025-01-01',
        ];
    }
}
