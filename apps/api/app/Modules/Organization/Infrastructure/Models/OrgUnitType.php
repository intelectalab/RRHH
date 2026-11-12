<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\OrgUnitTypeFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla org_unit_types · ORG-3. Niveles de unidad con los nombres de la organización.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property int $rank
 * @property string $status
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrgUnitType extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrgUnitTypeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'org_unit_types';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'active', 'rank' => 0];

    protected $casts = [
        'rank' => 'integer',
        'lock_version' => 'integer',
    ];

    protected static function newFactory(): OrgUnitTypeFactory
    {
        return OrgUnitTypeFactory::new();
    }
}
