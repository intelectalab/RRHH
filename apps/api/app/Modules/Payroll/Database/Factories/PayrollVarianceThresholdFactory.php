<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollVarianceThreshold;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de PayrollVarianceThreshold (pruebas de aislamiento y de la API).
 *
 * @extends Factory<PayrollVarianceThreshold>
 */
class PayrollVarianceThresholdFactory extends Factory
{
    protected $model = PayrollVarianceThreshold::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => PayrollRunFactory::legalEntity(),
            'threshold_pct' => '20.00',
        ];
    }
}
