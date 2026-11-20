<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\Payslip;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de prueba de Payslip (pruebas de aislamiento y de la API): una boleta publicada de una relación con
 * su periodo, creados por tablas.
 *
 * @extends Factory<Payslip>
 */
class PayslipFactory extends Factory
{
    protected $model = Payslip::class;

    public function definition(): array
    {
        return [
            'payroll_period_id' => fn (): string => PayrollRunFactory::period(),
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'legal_entity_id' => fn (array $a): string => (string) DB::table('employments')->where('id', $a['employment_id'])->value('legal_entity_id'),
            'number' => 'B-'.Str::upper(Str::random(10)),
            'kind' => 'regular',
            'status' => 'published',
            'currency' => 'PEN',
            'gross_amount' => '1000.00',
            'deductions_amount' => '100.00',
            'net_amount' => '900.00',
            'employer_contributions' => '90.00',
            'content' => json_encode(['lines' => []]),
            'closed_at' => now(),
            'published_at' => now(),
        ];
    }
}
