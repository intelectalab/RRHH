<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla holidays · CFG-8.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $holiday_calendar_id
 * @property Carbon $holiday_date
 * @property string $name
 * @property string $kind
 */
class Holiday extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'holidays';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['holiday_date' => 'date'];
}
