<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\PensionAffiliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Afiliación a una AFP con comisión sobre el flujo, vigente desde el ingreso.
 *
 * @extends Factory<PensionAffiliation>
 */
class PensionAffiliationFactory extends Factory
{
    protected $model = PensionAffiliation::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'pension_system' => 'afp',
            'pension_fund_code' => 'pe_afp_integra',
            'commission_scheme' => 'flow',
            'valid_from' => '2025-01-01',
        ];
    }
}
