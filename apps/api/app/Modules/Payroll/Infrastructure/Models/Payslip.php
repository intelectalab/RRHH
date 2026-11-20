<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayslipFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BOL-1 · Boleta de pago (ME-20). El caso de uso trabaja con el constructor de consultas; el modelo sirve al
 * alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class Payslip extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayslipFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payslips';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): PayslipFactory
    {
        return PayslipFactory::new();
    }
}
