<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\CtsDepositFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BSO-2 · Depósito semestral de CTS por trabajador con su entidad depositaria y su constancia.
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property string $employment_id
 * @property string $period_code
 * @property string $amount
 * @property string $currency
 * @property string|null $computable_remuneration
 * @property string|null $sixth_of_bonus
 * @property int|null $computable_months
 * @property int|null $computable_days
 * @property string|null $deposit_bank_code
 * @property string|null $deposit_reference
 */
class CtsDeposit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CtsDepositFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'cts_deposits';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): CtsDepositFactory
    {
        return CtsDepositFactory::new();
    }
}
