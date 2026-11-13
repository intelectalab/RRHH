<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\PersonnelActionFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HIS-2 · HIS-4 · Movimiento de personal con fecha efectiva. Su estado cambia solo por acciones
 * (PersonnelActionStateMachine): en aprobación, programado, aplicado, rechazado, anulado o reemplazado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $kind
 * @property CarbonInterface $effective_on
 * @property string|null $reason
 * @property string $status
 * @property string|null $requested_by_user_id
 * @property string|null $workflow_instance_id
 * @property CarbonInterface|null $applied_at
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $changes
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $replaces_personnel_action_id
 * @property string|null $replaced_by_personnel_action_id
 * @property bool $is_retroactive
 * @property CarbonInterface|null $affected_from
 * @property CarbonInterface|null $affected_to
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PersonnelAction extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PersonnelActionFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'personnel_actions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'effective_on' => 'date:Y-m-d', 'applied_at' => 'datetime', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime',
        'affected_from' => 'date:Y-m-d', 'affected_to' => 'date:Y-m-d', 'payload' => 'array', 'changes' => 'array',
        'is_retroactive' => 'boolean', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): PersonnelActionFactory
    {
        return PersonnelActionFactory::new();
    }
}
