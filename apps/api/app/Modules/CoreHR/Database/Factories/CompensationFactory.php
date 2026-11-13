<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\Compensation;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sueldo de ingreso de una relación laboral nueva (monto de prueba, no real).
 *
 * @extends Factory<Compensation>
 */
class CompensationFactory extends Factory
{
    protected $model = Compensation::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'base_salary' => '3000.00',
            'currency' => 'PEN',
            'pay_frequency' => 'monthly',
            'salary_basis' => 'monthly',
            'change_reason' => 'hire',
            'valid_from' => '2025-01-01',
        ];
    }
}
