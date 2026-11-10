<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $role_id
 * @property string $permission_code
 * @property string $scope
 */
class RolePermission extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'role_permissions';

    protected $guarded = ['id', 'tenant_id'];
}
