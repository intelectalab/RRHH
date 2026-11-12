<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\CostCenterFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ORG-4 · Centro de costo por empresa (o compartido) con jerarquía y vigencia (opened_on / closed_on).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $legal_entity_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $accounting_code
 * @property CarbonInterface|null $opened_on
 * @property CarbonInterface|null $closed_on
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CostCenter extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'cost_centers';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'opened_on' => 'date', 'closed_on' => 'date'];

    protected static function newFactory(): CostCenterFactory
    {
        return CostCenterFactory::new();
    }
}
