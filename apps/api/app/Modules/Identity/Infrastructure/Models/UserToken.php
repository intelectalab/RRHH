<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Token de un solo uso (invitación, OTP, recuperación). Solo se guarda su hash.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $purpose
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 * @property int $attempts
 */
class UserToken extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const PURPOSE_INVITE = 'invite';

    protected $table = 'user_tokens';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
