<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla vacation_accrual_periods · VAC-2. Año vacacional de una relación laboral: cuántos días le
 * corresponden, cuándo se ganan y hasta cuándo se gozan. Sus días disponibles salen del libro.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $period_start
 * @property string $period_end
 * @property string $entitled_days
 * @property string $accrued_days
 * @property string|null $enjoy_by
 * @property string $status
 * @property string|null $vacation_policy_id
 * @property array<string, mixed>|null $entitlement_detail
 * @property string|null $earned_on
 * @property bool $pending_validation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VacationAccrualPeriod extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUSES = ['accruing', 'earned', 'overdue', 'closed'];

    protected $table = 'vacation_accrual_periods';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'entitlement_detail' => 'array',
        'pending_validation' => 'boolean',
    ];
}
