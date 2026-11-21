<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\ExternalIncomeDeclaration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de ExternalIncomeDeclaration (pruebas de aislamiento y de la API).
 *
 * @extends Factory<ExternalIncomeDeclaration>
 */
class ExternalIncomeDeclarationFactory extends Factory
{
    protected $model = ExternalIncomeDeclaration::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'fiscal_year' => 2026,
            'kind' => 'previous_employer',
            'employer_tax_id' => '20123456789',
            'employer_name' => 'Empleador anterior',
            'gross_income' => '12000.00',
            'tax_withheld' => '300.00',
            'currency' => 'PEN',
            'status' => 'received',
        ];
    }
}
