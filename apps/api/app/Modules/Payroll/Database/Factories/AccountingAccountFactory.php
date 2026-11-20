<?php

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Infrastructure\Models\AccountingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Cuenta contable común a todas las empresas de la organización.
 *
 * @extends Factory<AccountingAccount>
 */
class AccountingAccountFactory extends Factory
{
    protected $model = AccountingAccount::class;

    public function definition(): array
    {
        return [
            'code' => '6'.Str::random(7),
            'name' => 'Cuenta '.Str::random(6),
            'kind' => 'expense',
        ];
    }
}
