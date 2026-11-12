<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * POS-2 · Cambio de estado de una posición: inalterable (solo inserción).
 *
 * @property string $id
 * @property string $position_id
 * @property string|null $action
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $reason
 * @property string|null $changed_by_user_id
 * @property CarbonInterface $effective_on
 * @property CarbonInterface $created_at
 */
class PositionStatusEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'position_status_events';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['effective_on' => 'date'];
}
