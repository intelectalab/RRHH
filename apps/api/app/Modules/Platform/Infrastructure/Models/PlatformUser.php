<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Carbon\CarbonInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Usuario de la consola del propietario del SaaS (SAS-1). Tabla de plataforma: no pertenece a
 * ninguna organización y nunca se mezcla con los usuarios de las organizaciones.
 *
 * @property string $id
 * @property string $email
 * @property string $full_name
 * @property string|null $password_hash
 * @property string $role
 * @property string|null $mfa_secret_enc
 * @property CarbonInterface|null $mfa_confirmed_at
 * @property int|null $mfa_last_used_step
 * @property string $status
 * @property int $failed_attempts
 * @property CarbonInterface|null $locked_until
 * @property CarbonInterface|null $last_login_at
 * @property CarbonInterface|null $deleted_at
 */
class PlatformUser extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasUuids;
    use SoftDeletes;

    /** Valores del enum platform_users.role. */
    public const ROLES = ['superadmin', 'support', 'billing', 'readonly'];

    protected $table = 'platform_users';

    protected $guarded = ['id', 'lock_version'];

    protected $hidden = ['password_hash', 'mfa_secret_enc'];

    protected $casts = [
        'mfa_confirmed_at' => 'datetime',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
        'failed_attempts' => 'integer',
        'mfa_last_used_step' => 'integer',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function canSignIn(): bool
    {
        return $this->status === 'active' && $this->deleted_at === null && ! $this->isLocked();
    }

    public function hasMfa(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret_enc !== null;
    }
}
