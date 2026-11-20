<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollRun;
use App\Modules\Payroll\Infrastructure\Models\PayrollRunAnomaly;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de PayrollRunAnomaly (pruebas de aislamiento y de la API).
 *
 * @extends Factory<PayrollRunAnomaly>
 */
class PayrollRunAnomalyFactory extends Factory
{
    protected $model = PayrollRunAnomaly::class;

    public function definition(): array
    {
        return [
            'payroll_run_id' => fn (): string => PayrollRun::factory()->create()->id,
            'rule_code' => 'net_increase',
            'severity' => 'warning',
            'explanation' => 'Variación del neto',
            'status' => 'open',
        ];
    }
}
