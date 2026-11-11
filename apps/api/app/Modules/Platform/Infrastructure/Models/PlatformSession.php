<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesión de la consola (cookie propia, SAS-1). Solo se guarda el hash del token.
 *
 * @property string $id
 * @property string $platform_user_id
 * @property string $session_hash
 * @property string|null $ip
 * @property string|null $user_agent
 * @property bool $mfa_passed
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $revoked_at
 * @property PlatformUser|null $user
 */
class PlatformSession extends Model
{
    use HasUuids;

    protected $table = 'platform_sessions';

    protected $guarded = ['id'];

    protected $hidden = ['session_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'mfa_passed' => 'boolean',
    ];

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
