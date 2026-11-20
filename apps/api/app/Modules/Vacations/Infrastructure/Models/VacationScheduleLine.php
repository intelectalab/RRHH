<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Modules\Vacations\Database\Factories\VacationScheduleLineFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * VAC-5 · Fechas propuestas a un trabajador en el rol anual.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $vacation_schedule_id
 * @property string $employment_id
 * @property string $starts_on
 * @property string $ends_on
 * @property string|null $days
 * @property string $status
 * @property string|null $proposed_by_user_id
 * @property CarbonInterface|null $responded_at
 * @property string|null $response_note
 * @property string|null $vacation_request_id
 * @property int $lock_version
 */
class VacationScheduleLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VacationScheduleLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vacation_schedule_lines';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'proposed'];

    protected $casts = ['responded_at' => 'datetime', 'lock_version' => 'integer'];

    /** Fecha laboral sin hora (AAAA-MM-DD). */
    public function day(string $column): string
    {
        return substr((string) $this->getRawOriginal($column, $this->getAttribute($column)), 0, 10);
    }

    protected static function newFactory(): VacationScheduleLineFactory
    {
        return VacationScheduleLineFactory::new();
    }
}
