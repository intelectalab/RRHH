<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Modules\Workflow\Database\Factories\AutomationRuleFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla automation_rules · WFL-5 · Regla CUANDO (evento) → SI (condiciones) → ENTONCES (acciones).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $trigger_event
 * @property array<string, mixed>|null $conditions
 * @property array<int, array<string, mixed>> $actions
 * @property string $status
 * @property Carbon|null $last_run_at
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class AutomationRule extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AutomationRuleFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'automation_rules';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['conditions' => 'array', 'actions' => 'array', 'last_run_at' => 'datetime', 'lock_version' => 'integer'];

    protected static function newFactory(): AutomationRuleFactory
    {
        return AutomationRuleFactory::new();
    }
}
