<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollPaymentBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba de PayrollPaymentBatch (pruebas de aislamiento y de la API).
 *
 * @extends Factory<PayrollPaymentBatch>
 */
class PayrollPaymentBatchFactory extends Factory
{
    protected $model = PayrollPaymentBatch::class;

    public function definition(): array
    {
        return [
            'payroll_period_id' => fn (): string => PayrollRunFactory::period(),
            'legal_entity_id' => fn (array $a): string => (string) DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')
                ->where('p.id', $a['payroll_period_id'])->value('g.legal_entity_id'),
            'kind' => 'salary',
            'channel' => 'bank_file',
            'bank_code' => 'BCP',
            'status' => 'draft',
            'currency' => 'PEN',
        ];
    }
}
