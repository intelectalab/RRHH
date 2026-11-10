<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Modules\Workflow\Database\Factories\ApprovalRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tabla workflow_instances.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_definition_version_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $status
 * @property string|null $started_by_user_id
 * @property string|null $current_step_id
 * @property array<string, mixed> $context
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApprovalRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'workflow_instances';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['context' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'lock_version' => 'integer'];

    /**
     * @return HasMany<WorkflowTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkflowTask::class, 'workflow_instance_id');
    }

    protected static function newFactory(): ApprovalRequestFactory
    {
        return ApprovalRequestFactory::new();
    }
}
