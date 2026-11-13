<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\InsurancePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Póliza de Vida Ley de una empresa nueva.
 *
 * @extends Factory<InsurancePolicy>
 */
class InsurancePolicyFactory extends Factory
{
    protected $model = InsurancePolicy::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => EmploymentFactory::legalEntity(),
            'kind' => 'life',
            'insurer' => 'Aseguradora '.$this->faker->lastName(),
            'policy_number' => 'VL-'.$this->faker->unique()->numerify('######'),
            'starts_on' => '2025-01-01',
            'status' => 'active',
        ];
    }
}
