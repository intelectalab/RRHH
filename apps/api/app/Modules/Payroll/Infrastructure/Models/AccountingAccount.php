<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\AccountingAccountFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CPT-4 · Cuenta contable de la planilla. El servicio ConceptAccounting la administra con consultas directas;
 * el modelo sirve a la siembra de datos de prueba (aislamiento entre organizaciones).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $kind
 */
class AccountingAccount extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AccountingAccountFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'accounting_accounts';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): AccountingAccountFactory
    {
        return AccountingAccountFactory::new();
    }
}
