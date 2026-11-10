<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla approval_delegations.
 *
 * @property string $id
 * @property string $tenant_id
 */
class ApprovalDelegation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'approval_delegations';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'revoked_at' => 'datetime'];
}
