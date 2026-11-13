<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\InsuranceCoverage;
use App\Modules\CoreHR\Infrastructure\Models\InsurancePolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cobertura vigente de una relación laboral en una póliza de su empresa.
 *
 * @extends Factory<InsuranceCoverage>
 */
class InsuranceCoverageFactory extends Factory
{
    protected $model = InsuranceCoverage::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'insurance_policy_id' => fn (array $a): string => InsurancePolicy::factory()->create([
                'legal_entity_id' => (string) Employment::query()->whereKey($a['employment_id'])->value('legal_entity_id'),
            ])->id,
            'covered_from' => '2025-01-01',
        ];
    }
}
