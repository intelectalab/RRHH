<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Modules\Notifications\Database\Factories\NotificationDeliveryFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla notification_deliveries.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $notification_id
 * @property string $channel
 * @property string|null $recipient_address_enc
 * @property string $status
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property string|null $error
 * @property string|null $purpose
 * @property string|null $template_code
 * @property string|null $fallback_from
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 */
class NotificationDelivery extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'notification_deliveries';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['sent_at' => 'datetime', 'delivered_at' => 'datetime', 'attempts' => 'integer'];

    protected static function newFactory(): NotificationDeliveryFactory
    {
        return NotificationDeliveryFactory::new();
    }
}
