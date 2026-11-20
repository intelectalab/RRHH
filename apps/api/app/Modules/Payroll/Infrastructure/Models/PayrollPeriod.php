<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollPeriodFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PER-1 · Periodo de planilla de un grupo (ME-19). El estado solo cambia por acciones.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payroll_group_id
 * @property int $period_year
 * @property int $period_number
 * @property string $kind
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property CarbonInterface|null $payment_date
 * @property CarbonInterface|null $attendance_from
 * @property CarbonInterface|null $attendance_to
 * @property string|null $statutory_period_code
 * @property string $status
 * @property CarbonInterface|null $closed_at
 * @property string|null $closed_by_user_id
 * @property string|null $opened_by_user_id
 * @property string|null $submitted_by_user_id
 * @property CarbonInterface|null $submitted_at
 * @property string|null $approved_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property string|null $return_reason
 * @property string|null $reopen_reason
 * @property CarbonInterface|null $time_consumed_at
 * @property string|null $time_consumed_by_user_id
 * @property int $lock_version
 */
class PayrollPeriod extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollPeriodFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payroll_periods';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'open', 'kind' => 'regular'];

    protected $casts = [
        'period_year' => 'integer', 'period_number' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'payment_date' => 'date',
        'attendance_from' => 'date', 'attendance_to' => 'date', 'closed_at' => 'datetime', 'submitted_at' => 'datetime',
        'approved_at' => 'datetime', 'time_consumed_at' => 'datetime', 'lock_version' => 'integer',
    ];

    public function day(string $attribute): ?string
    {
        $value = $this->getAttribute($attribute);

        return $value instanceof CarbonInterface ? $value->toDateString() : null;
    }

    /** Fechas del corte de tiempo: las del periodo si no se fijaron (PER-3). */
    public function cutoffFrom(): string
    {
        return $this->day('attendance_from') ?? (string) $this->day('starts_on');
    }

    public function cutoffTo(): string
    {
        return $this->day('attendance_to') ?? (string) $this->day('ends_on');
    }

    protected static function newFactory(): PayrollPeriodFactory
    {
        return PayrollPeriodFactory::new();
    }
}
