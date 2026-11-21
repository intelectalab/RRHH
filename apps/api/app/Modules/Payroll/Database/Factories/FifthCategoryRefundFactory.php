<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\FifthCategoryRefund;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de FifthCategoryRefund (pruebas de aislamiento y de la API).
 *
 * @extends Factory<FifthCategoryRefund>
 */
class FifthCategoryRefundFactory extends Factory
{
    protected $model = FifthCategoryRefund::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'fiscal_year' => 2026,
            'origin' => 'worker_declaration',
            'amount' => '150.00',
            'currency' => 'PEN',
            'compensated_amount' => '0',
            'status' => 'pending',
        ];
    }
}
