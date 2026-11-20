<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\LegalEntityAnnualFigure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba de LegalEntityAnnualFigure (pruebas de aislamiento y de la API).
 *
 * @extends Factory<LegalEntityAnnualFigure>
 */
class LegalEntityAnnualFigureFactory extends Factory
{
    protected $model = LegalEntityAnnualFigure::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => PayrollRunFactory::legalEntity(),
            'fiscal_year' => random_int(2000, 2099),
            'net_sales' => '100000.00',
            'currency' => 'PEN',
            'uit_value' => '5000.00',
        ];
    }
}
