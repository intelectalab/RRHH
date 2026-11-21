<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PER-13 · Resultado del sistema anterior por trabajador y concepto, importado para la planilla en paralelo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payroll_period_id
 * @property string $employment_id
 * @property string $concept_code
 * @property string $amount
 * @property string $currency
 * @property string|null $import_job_id
 */
class PayrollLegacyResult extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'payroll_legacy_results';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];
}
