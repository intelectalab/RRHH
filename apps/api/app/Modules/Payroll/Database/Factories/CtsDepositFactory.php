<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\CtsDeposit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de CtsDeposit (pruebas de aislamiento y de la API).
 *
 * @extends Factory<CtsDeposit>
 */
class CtsDepositFactory extends Factory
{
    protected $model = CtsDeposit::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'period_code' => 'CTS-'.random_int(2000, 2099).'-05',
            'amount' => '1000.00',
            'currency' => 'PEN',
            'status' => 'calculated',
        ];
    }
}
