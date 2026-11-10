<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Modules\Notifications\Database\Factories\NotificationFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla notifications.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $recipient_user_id
 * @property string $category
 * @property string $title
 * @property string|null $body
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $action_url
 * @property string $priority
 * @property Carbon|null $read_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 */
class Notification extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notifications';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['read_at' => 'datetime', 'archived_at' => 'datetime'];

    protected static function newFactory(): NotificationFactory
    {
        return NotificationFactory::new();
    }
}
