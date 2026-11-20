<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\FifthCategoryLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos de prueba del libro de quinta (pruebas de aislamiento y de la API).
 *
 * @extends Factory<FifthCategoryLedgerEntry>
 */
class FifthCategoryLedgerEntryFactory extends Factory
{
    protected $model = FifthCategoryLedgerEntry::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => PayrollRunFactory::employment(),
            'fiscal_year' => 2026,
            'period_month' => 1,
            'entry_kind' => 'monthly',
            'withholding_amount' => '100.00',
            'currency' => 'PEN',
            'uit_value' => '5000.00',
            'parameters' => [],
            'trace' => ['reason' => 'fixture'],
            'engine_version' => 'quinta-1',
        ];
    }
}
