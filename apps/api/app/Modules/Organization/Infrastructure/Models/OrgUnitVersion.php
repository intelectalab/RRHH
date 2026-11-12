<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\Vigencia\HasValidity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla org_unit_versions · ORG-3. Estructura de una unidad con vigencia: se escribe con
 * EffectiveDated (cerrar la vigente e insertar otra), nunca se actualiza en sitio.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $org_unit_id
 * @property string|null $parent_id
 * @property string $name
 * @property string|null $org_unit_type_id
 * @property string|null $head_position_id
 * @property string|null $cost_center_id
 * @property string|null $reason
 * @property string|null $changed_by_user_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $created_at
 */
class OrgUnitVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use HasValidity;

    protected $table = 'org_unit_versions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];
}
