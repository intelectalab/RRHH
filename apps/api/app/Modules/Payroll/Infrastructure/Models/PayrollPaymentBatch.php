<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollPaymentBatchFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PER-10 · Lote de pago de un periodo por banco (archivo, confirmación y estado).
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class PayrollPaymentBatch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollPaymentBatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payroll_payment_batches';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): PayrollPaymentBatchFactory
    {
        return PayrollPaymentBatchFactory::new();
    }
}
