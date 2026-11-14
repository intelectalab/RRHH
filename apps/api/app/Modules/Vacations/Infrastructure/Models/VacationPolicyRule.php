<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\Vigencia\HasValidity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla vacation_policy_rules · VAC-1. Reglas de una política con vigencia: se cambian con
 * EffectiveDated::change (cierra la fila vigente e inserta otra), nunca en sitio.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vacation_policy_id
 * @property string|null $days_per_year
 * @property bool $counts_calendar_days
 * @property string|null $min_days_per_request
 * @property bool $allow_advance
 * @property string|null $max_advance_days
 * @property bool $allow_sale
 * @property string|null $max_sale_days
 * @property int|null $max_accumulated_periods
 * @property bool $requires_hr_validation
 * @property string|null $approval_workflow_definition_id
 * @property string $valid_from
 * @property string|null $valid_to
 */
class VacationPolicyRule extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use HasValidity;

    protected $table = 'vacation_policy_rules';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'counts_calendar_days' => 'boolean',
        'allow_advance' => 'boolean',
        'allow_sale' => 'boolean',
        'requires_hr_validation' => 'boolean',
        'max_accumulated_periods' => 'integer',
    ];
}
