<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollConcept;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Concepto propio en borrador (sin versión publicada).
 *
 * @extends Factory<PayrollConcept>
 */
class PayrollConceptFactory extends Factory
{
    protected $model = PayrollConcept::class;

    public function definition(): array
    {
        return [
            'code' => 'c_'.Str::lower(Str::random(10)),
            'name' => 'Bono '.Str::random(6),
            'kind' => 'earning',
            'calculation_method' => 'input',
            'status' => 'draft',
        ];
    }
}
