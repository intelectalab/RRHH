<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollVarianceThresholdFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PER-7 · Umbral de variación por empresa y concepto.
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class PayrollVarianceThreshold extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollVarianceThresholdFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'payroll_variance_thresholds';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): PayrollVarianceThresholdFactory
    {
        return PayrollVarianceThresholdFactory::new();
    }
}
