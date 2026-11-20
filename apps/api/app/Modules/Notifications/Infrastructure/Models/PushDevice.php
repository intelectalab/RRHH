<?php

namespace App\Modules\Notifications\Infrastructure\Models;

use App\Modules\Notifications\Database\Factories\PushDeviceFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla push_devices (NOT-4): la suscripción va cifrada en token_enc y se busca por token_hash.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $platform
 * @property string $token_enc
 * @property string $token_hash
 * @property string|null $label
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 */
class PushDevice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PushDeviceFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'push_devices';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function newFactory(): PushDeviceFactory
    {
        return PushDeviceFactory::new();
    }
}
