<?php

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Database\Factories\ActivityEventFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lectura de activity_events (AUD-3 / AUD-5). Se escribe solo desde los servicios del módulo.
 */
class ActivityEvent extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $table = 'activity_events';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): ActivityEventFactory
    {
        return ActivityEventFactory::new();
    }
}
