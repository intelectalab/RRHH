<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollRunAnomalyFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PER-7 · Anomalía explicada de una corrida (revisar o descartar con nota).
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 */
class PayrollRunAnomaly extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollRunAnomalyFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payroll_run_anomalies';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): PayrollRunAnomalyFactory
    {
        return PayrollRunAnomalyFactory::new();
    }
}
