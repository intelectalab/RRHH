<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión web activa y revocable. Solo se guarda el hash del token de la cookie.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $session_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $last_seen_at
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $device_label
 * @property CarbonInterface|null $created_at
 */
class UserSession extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'user_sessions';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['session_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'mfa_passed' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
