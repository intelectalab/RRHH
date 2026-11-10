<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla notification_preferences.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'notification_preferences';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['enabled' => 'boolean'];
}
