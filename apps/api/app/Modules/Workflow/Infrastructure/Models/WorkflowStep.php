<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla workflow_steps.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_definition_version_id
 * @property string $name
 * @property int $sort_order
 * @property string|null $approval_mode
 * @property int|null $sla_hours
 * @property array<string, mixed>|null $action_config
 * @property array<string, mixed>|null $escalation_config
 */
class WorkflowStep extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_steps';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['condition' => 'array', 'action_config' => 'array', 'escalation_config' => 'array', 'sort_order' => 'integer', 'sla_hours' => 'integer'];
}
