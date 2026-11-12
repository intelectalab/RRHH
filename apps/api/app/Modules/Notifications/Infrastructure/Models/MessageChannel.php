<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla message_channels: proveedor y remitente de un canal de mensajería de la organización.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $channel
 * @property string $provider
 * @property string|null $sender_identity
 * @property string $status
 * @property string $verification_status
 * @property Carbon|null $verified_at
 * @property string|null $verification_error
 * @property Carbon|null $updated_at
 */
class MessageChannel extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** Proveedor de correo de la plataforma: el remitente propio se verifica allí. */
    public const PLATFORM_EMAIL = 'platform';

    protected $table = 'message_channels';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $hidden = ['config_enc'];

    protected $casts = ['verified_at' => 'datetime'];
}
