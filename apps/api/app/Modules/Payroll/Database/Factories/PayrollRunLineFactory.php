<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\PayrollRunEmployment;
use App\Modules\Payroll\Infrastructure\Models\PayrollRunLine;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de prueba de PayrollRunLine (pruebas de aislamiento y de la API).
 *
 * @extends Factory<PayrollRunLine>
 */
class PayrollRunLineFactory extends Factory
{
    protected $model = PayrollRunLine::class;

    public function definition(): array
    {
        return [
            'payroll_run_employment_id' => fn (): string => PayrollRunEmployment::factory()->create()->id,
            'payroll_run_id' => fn (array $a): string => (string) PayrollRunEmployment::query()->whereKey($a['payroll_run_employment_id'])->value('payroll_run_id'),
            'payroll_concept_id' => function (): string {
                $tenant = TenancyScope::requireTenant();

                return (string) DB::table('payroll_concepts')->insertGetId(['tenant_id' => $tenant, 'code' => 'C'.Str::random(6), 'name' => 'Concepto', 'kind' => 'earning']);
            },
            'amount' => '100.00',
            'currency' => 'PEN',
            'origin' => 'calculated',
            'trace' => ['source' => 'formula', 'formula' => 'sueldo', 'variables' => [], 'parameters' => [], 'concepts' => [], 'incidents' => []],
        ];
    }
}
