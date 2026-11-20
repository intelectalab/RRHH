<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollRun;
use App\Modules\Payroll\Infrastructure\Models\PayrollRunEmployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de PayrollRunEmployment (pruebas de aislamiento y de la API).
 *
 * @extends Factory<PayrollRunEmployment>
 */
class PayrollRunEmploymentFactory extends Factory
{
    protected $model = PayrollRunEmployment::class;

    public function definition(): array
    {
        return [
            'payroll_run_id' => fn (): string => PayrollRun::factory()->create()->id,
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'status' => 'calculated',
            'currency' => 'PEN',
        ];
    }
}
