<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\Settlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de Settlement (pruebas de aislamiento y de la API).
 *
 * @extends Factory<Settlement>
 */
class SettlementFactory extends Factory
{
    protected $model = Settlement::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'termination_date' => '2026-08-15',
            'currency' => 'PEN',
            'status' => 'draft',
            'calculation_number' => 0,
        ];
    }
}
