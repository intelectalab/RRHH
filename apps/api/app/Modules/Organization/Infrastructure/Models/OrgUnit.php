<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\OrgUnitFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla org_units · ORG-3. Identidad de la unidad y foto de su estructura vigente hoy (superior,
 * nombre, tipo, responsable, centro de costo, ruta). La historia está en org_unit_versions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $legal_entity_id
 * @property string|null $parent_id
 * @property string $code
 * @property string $name
 * @property string|null $org_unit_type_id
 * @property string|null $head_position_id
 * @property string|null $cost_center_id
 * @property string|null $path
 * @property int|null $depth
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string $status
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrgUnit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrgUnitFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'org_units';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'active'];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'depth' => 'integer',
        'lock_version' => 'integer',
    ];

    protected static function newFactory(): OrgUnitFactory
    {
        return OrgUnitFactory::new();
    }
}
