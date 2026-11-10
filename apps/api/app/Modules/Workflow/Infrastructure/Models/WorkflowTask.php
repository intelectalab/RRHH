<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla workflow_tasks.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_instance_id
 * @property string $workflow_step_id
 * @property string|null $assignee_user_id
 * @property string|null $assignee_role_id
 * @property string|null $delegated_from_user_id
 * @property string $status
 * @property Carbon|null $due_at
 * @property Carbon|null $resume_at
 * @property Carbon|null $completed_at
 * @property int $sequence
 * @property Carbon|null $created_at
 */
class WorkflowTask extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_tasks';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['due_at' => 'datetime', 'resume_at' => 'datetime', 'completed_at' => 'datetime', 'sequence' => 'integer'];
}
