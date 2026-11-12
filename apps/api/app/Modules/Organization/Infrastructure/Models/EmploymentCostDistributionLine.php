<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ORG-4 · Línea de una distribución: centro de costo y porcentaje (numeric(5,2), se lee como texto).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_cost_distribution_id
 * @property string $cost_center_id
 * @property string $percentage
 */
class EmploymentCostDistributionLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'employment_cost_distribution_lines';

    protected $guarded = ['id', 'tenant_id'];
}
