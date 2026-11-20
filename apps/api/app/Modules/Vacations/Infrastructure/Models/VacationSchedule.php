<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Modules\Vacations\Database\Factories\VacationScheduleFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * VAC-5 · Campaña del rol anual de vacaciones de una empresa y un año.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property int $year
 * @property string $name
 * @property string $status
 * @property string|null $opened_by_user_id
 * @property CarbonInterface|null $closed_at
 * @property int $lock_version
 */
class VacationSchedule extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VacationScheduleFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vacation_schedules';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'open'];

    protected $casts = ['year' => 'integer', 'closed_at' => 'datetime', 'lock_version' => 'integer'];

    protected static function newFactory(): VacationScheduleFactory
    {
        return VacationScheduleFactory::new();
    }
}
