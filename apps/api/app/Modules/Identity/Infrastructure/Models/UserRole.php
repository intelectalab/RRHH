<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Asignación de un rol a un usuario, con vigencia y alcance opcionales.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role_id
 * @property string|null $scope_override
 */
class UserRole extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'user_roles';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];
}
