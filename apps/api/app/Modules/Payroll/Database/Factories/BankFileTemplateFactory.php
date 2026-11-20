<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\BankFileTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Datos de prueba de BankFileTemplate (pruebas de aislamiento y de la API).
 *
 * @extends Factory<BankFileTemplate>
 */
class BankFileTemplateFactory extends Factory
{
    protected $model = BankFileTemplate::class;

    public function definition(): array
    {
        return [
            'code' => 'banco_'.Str::lower(Str::random(8)),
            'bank_code' => 'BANCO',
            'name' => 'Plantilla de prueba',
            'kind' => 'salary',
            'definition' => json_encode(['separator' => ';', 'detail' => [['source' => 'item.account_number'], ['source' => 'item.amount', 'decimals' => 2]]]),
            'status' => 'active',
        ];
    }
}
