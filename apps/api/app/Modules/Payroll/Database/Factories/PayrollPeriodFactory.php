<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollGroup;
use App\Modules\Payroll\Infrastructure\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Periodo mensual abierto de un grupo nuevo.
 *
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    public function definition(): array
    {
        return [
            'payroll_group_id' => fn (): string => (string) PayrollGroup::factory()->create()->id,
            'kind' => 'regular',
            'period_year' => 2026,
            'period_number' => 11,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'statutory_period_code' => '202611',
            'status' => 'open',
        ];
    }
}
