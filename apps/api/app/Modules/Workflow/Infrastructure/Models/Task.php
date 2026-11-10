<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Modules\Workflow\Database\Factories\TaskFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla hr_tasks · WFL-8 · Tarea interna de RRHH.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string|null $description
 * @property string|null $assignee_user_id
 * @property string|null $assignee_role_id
 * @property string|null $created_by_user_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $related_employment_id
 * @property Carbon|null $due_on
 * @property string $priority
 * @property string $status
 * @property Carbon|null $completed_at
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Task extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'hr_tasks';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['due_on' => 'date', 'completed_at' => 'datetime', 'lock_version' => 'integer'];

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }
}
