<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla workflow_decisions.
 *
 * @property string $id
 * @property string $tenant_id
 */
class WorkflowDecision extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_decisions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    public const UPDATED_AT = null;

    protected $casts = ['decided_at' => 'datetime'];
}
