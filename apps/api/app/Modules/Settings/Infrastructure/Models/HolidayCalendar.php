<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\HolidayCalendarFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla holiday_calendars · CFG-8.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $country_code
 * @property string|null $site_id
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HolidayCalendar extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HolidayCalendarFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'holiday_calendars';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['is_default' => 'boolean'];

    /**
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class, 'holiday_calendar_id');
    }

    protected static function newFactory(): HolidayCalendarFactory
    {
        return HolidayCalendarFactory::new();
    }
}
