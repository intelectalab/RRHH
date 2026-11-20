<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\ProfitSharingDistribution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de ProfitSharingDistribution (pruebas de aislamiento y de la API).
 *
 * @extends Factory<ProfitSharingDistribution>
 */
class ProfitSharingDistributionFactory extends Factory
{
    protected $model = ProfitSharingDistribution::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => PayrollRunFactory::legalEntity(),
            'fiscal_year' => random_int(2000, 2099),
            'net_income_before_tax' => '100000.00',
            'currency' => 'PEN',
            'activity' => 'otras_actividades',
            'status' => 'draft',
        ];
    }
}
