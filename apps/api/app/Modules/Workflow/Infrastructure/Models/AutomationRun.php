<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Modules\Workflow\Database\Factories\AutomationRunFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla automation_runs · WFL-5 · Una ejecución de una regla para un evento, con el registro de cada acción.
 *
 * log: {"event_type", "payload", "attempts", "next_retry_at", "actions": [{"index", "type", "status", "result"|"error"}]}
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $automation_rule_id
 * @property string|null $trigger_event_id
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $log
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class AutomationRun extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AutomationRunFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'automation_runs';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['log' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    protected static function newFactory(): AutomationRunFactory
    {
        return AutomationRunFactory::new();
    }
}
