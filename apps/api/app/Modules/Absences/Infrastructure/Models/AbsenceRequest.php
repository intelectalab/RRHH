<?php

namespace App\Modules\Absences\Infrastructure\Models;

use App\Modules\Absences\Database\Factories\AbsenceRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AUS-2 · Ausencia o permiso (ME-16). Las fechas laborales (starts_on, ends_on) son la referencia; los
 * instantes starts_at / ends_at (fin exclusivo, zona de la empresa) alimentan la exclusión de solapes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $absence_type_id
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property string|null $starts_on
 * @property string|null $ends_on
 * @property string|null $start_time
 * @property string|null $end_time
 * @property string|null $days
 * @property string|null $hours
 * @property string|null $calendar_days
 * @property string $status
 * @property string|null $reason
 * @property string|null $workflow_instance_id
 * @property string|null $requested_by_user_id
 * @property string|null $employer_paid_days
 * @property string|null $subsidized_days
 * @property bool $registered_directly
 * @property string|null $document_due_on
 * @property string|null $exception_reason
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AbsenceRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AbsenceRequestFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** Tipo de registro para adjuntos, auditoría y aprobaciones. */
    public const SUBJECT = 'absence_request';

    protected $table = 'absence_requests';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'draft', 'registered_directly' => false];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime', 'registered_directly' => 'boolean',
        'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime', 'lock_version' => 'integer',
    ];

    /** Fecha laboral sin hora (la base la guarda como date; aquí siempre AAAA-MM-DD). */
    public function day(string $column): string
    {
        return substr((string) $this->getRawOriginal($column, $this->getAttribute($column)), 0, 10);
    }

    protected static function newFactory(): AbsenceRequestFactory
    {
        return AbsenceRequestFactory::new();
    }
}
