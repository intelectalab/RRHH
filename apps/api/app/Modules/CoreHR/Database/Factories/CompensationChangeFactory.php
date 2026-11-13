<?php

namespace App\Modules\CoreHR\Database\Factories;

use App\Modules\CoreHR\Infrastructure\Models\CompensationChange;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Cambio de sueldo pendiente de aprobación de una relación laboral nueva (monto de prueba, no real).
 *
 * @extends Factory<CompensationChange>
 */
class CompensationChangeFactory extends Factory
{
    protected $model = CompensationChange::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => Employment::factory()->create()->id,
            'kind' => CompensationChange::KIND,
            'effective_on' => '2026-03-01',
            'reason' => 'merit',
            'status' => 'pending_approval',
            'payload' => ['base_salary' => ['amount' => '3500.00', 'currency' => 'PEN'], 'pay_frequency' => 'monthly', 'salary_basis' => 'monthly', 'is_integral_salary' => false],
        ];
    }
}
