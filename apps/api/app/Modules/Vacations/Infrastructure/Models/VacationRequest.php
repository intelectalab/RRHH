<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Modules\Vacations\Database\Factories\VacationRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * VAC-3 · Solicitud de vacaciones (ME-15). days es lo que descuenta del saldo según el cómputo de la
 * política (calendario o hábil); calendar_days, los días calendario del rango; advance_days, la parte
 * tomada a cuenta del periodo en curso (adelanto).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $starts_on
 * @property string $ends_on
 * @property string $days
 * @property string|null $calendar_days
 * @property string|null $advance_days
 * @property string $kind
 * @property string $status
 * @property string|null $workflow_instance_id
 * @property string|null $notes
 * @property string|null $requested_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property string|null $exception_reason
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class VacationRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VacationRequestFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** Tipo de registro para auditoría, aprobaciones y libro de vacaciones. */
    public const SUBJECT = 'vacation_request';

    protected $table = 'vacation_requests';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'draft', 'kind' => 'leave'];

    protected $casts = [
        'approved_at' => 'datetime', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'cancelled_at' => 'datetime',
        'started_at' => 'datetime', 'completed_at' => 'datetime', 'lock_version' => 'integer',
    ];

    /** Fecha laboral sin hora (AAAA-MM-DD). */
    public function day(string $column): string
    {
        return substr((string) $this->getRawOriginal($column, $this->getAttribute($column)), 0, 10);
    }

    protected static function newFactory(): VacationRequestFactory
    {
        return VacationRequestFactory::new();
    }
}
