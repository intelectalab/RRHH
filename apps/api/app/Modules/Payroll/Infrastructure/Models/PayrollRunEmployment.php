<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollRunEmploymentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NOM-8 · Resultado de una relación en una corrida.
 *
 * @property string $id
 * @property string $tenant_id
 */
class PayrollRunEmployment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollRunEmploymentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payroll_run_employments';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['context_snapshot' => 'array'];

    protected static function newFactory(): PayrollRunEmploymentFactory
    {
        return PayrollRunEmploymentFactory::new();
    }
}
