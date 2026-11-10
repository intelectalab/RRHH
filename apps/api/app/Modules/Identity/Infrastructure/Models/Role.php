<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\RoleFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Rol de la organización: de sistema (copiado de la plantilla) o personalizado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property string|null $system_role_code
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Role extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'roles';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'is_system' => 'boolean',
        'lock_version' => 'integer',
    ];

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRole::class, 'role_id');
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
