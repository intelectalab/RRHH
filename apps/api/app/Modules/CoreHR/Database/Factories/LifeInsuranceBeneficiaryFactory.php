<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\LifeInsuranceBeneficiary;
use App\Shared\Security\FieldCipher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Beneficiaria única (100 %) de una declaración de Vida Ley vigente.
 *
 * @extends Factory<LifeInsuranceBeneficiary>
 */
class LifeInsuranceBeneficiaryFactory extends Factory
{
    protected $model = LifeInsuranceBeneficiary::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'full_name_enc' => fn (): string => FieldCipher::encrypt($this->faker->firstName().' '.$this->faker->lastName()),
            'relationship' => 'spouse',
            'percentage' => '100.00',
            'declared_on' => '2025-01-15',
            'declaration_id' => (string) Str::uuid(),
            'status' => 'current',
        ];
    }
}
