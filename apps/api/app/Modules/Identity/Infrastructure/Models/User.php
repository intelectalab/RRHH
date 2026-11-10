<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\UserFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cuenta de acceso (C-01). Puede o no estar ligada a una persona; el correo no es obligatorio (C-09).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $person_id
 * @property string $display_name
 * @property string|null $locale
 * @property string|null $timezone
 * @property string $status
 * @property string|null $password_hash
 * @property CarbonInterface|null $password_changed_at
 * @property bool $must_change_password
 * @property int $failed_attempts
 * @property CarbonInterface|null $locked_until
 * @property CarbonInterface|null $last_login_at
 * @property int $lock_version
 */
class User extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use BelongsToTenant;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ALUMNI = 'alumni';

    protected $table = 'users';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'password_changed_at' => 'datetime',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'must_change_password' => 'boolean',
        'is_service_account' => 'boolean',
        'failed_attempts' => 'integer',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return HasMany<UserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(UserIdentity::class, 'user_id');
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    public function canSignIn(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->locked_until === null || $this->locked_until->isPast();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
