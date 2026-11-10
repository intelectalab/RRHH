<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Modules\Notifications\Database\Factories\NotificationTemplateFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla notification_templates.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $channel
 * @property string $locale
 * @property string|null $subject
 * @property string $body
 * @property array<int, string>|null $variables
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationTemplate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'notification_templates';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['variables' => 'array', 'is_system' => 'boolean'];

    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }
}
