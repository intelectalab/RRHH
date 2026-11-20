<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\HeadcountPlanLineFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * POS-5 · Línea de un escenario: una posición real copiada (baseline, freeze, remove) o una plaza nueva (add).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $headcount_scenario_id
 * @property string $org_unit_id
 * @property string $job_profile_id
 * @property string|null $site_id
 * @property int $planned_count
 * @property CarbonInterface|null $planned_start_on
 * @property string|null $projected_cost
 * @property string|null $currency
 * @property string|null $notes
 * @property string|null $position_id
 * @property string $change
 * @property string|null $title
 * @property string|null $position_request_id
 */
class HeadcountPlanLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HeadcountPlanLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'headcount_plan_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['planned_count' => 'integer', 'planned_start_on' => 'date'];

    protected static function newFactory(): HeadcountPlanLineFactory
    {
        return HeadcountPlanLineFactory::new();
    }
}
