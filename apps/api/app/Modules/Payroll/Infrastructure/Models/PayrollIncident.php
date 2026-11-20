<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollIncidentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PER-2 · Incidencia del periodo: valor variable por trabajador y concepto, con su origen y su autor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payroll_period_id
 * @property string $employment_id
 * @property string $payroll_concept_id
 * @property string|null $quantity
 * @property string|null $rate
 * @property string|null $amount
 * @property string|null $currency
 * @property string $source
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $retro_period_id
 * @property string|null $notes
 * @property string $status
 * @property string|null $created_by_user_id
 * @property string|null $updated_by_user_id
 * @property string|null $decided_by_user_id
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 */
class PayrollIncident extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollIncidentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'payroll_incidents';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'pending', 'source' => 'manual'];

    protected $casts = ['decided_at' => 'datetime', 'lock_version' => 'integer'];

    protected static function newFactory(): PayrollIncidentFactory
    {
        return PayrollIncidentFactory::new();
    }
}
