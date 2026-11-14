<?php

namespace App\Modules\Absences\Infrastructure\Models;

use App\Modules\Absences\Database\Factories\AbsenceSubsidyCaseFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AUS-3 · Expediente de subsidio de un descanso (absence_subsidy_cases).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $absence_request_id
 * @property string $employment_id
 * @property string $kind
 * @property string $status
 * @property string $subsidized_days
 * @property string|null $starts_on
 * @property string|null $ends_on
 * @property int|null $year
 * @property string|null $filing_due_on
 * @property string|null $filed_on
 * @property string|null $filing_reference
 * @property string|null $amount_requested
 * @property string|null $amount_approved
 * @property string|null $amount_reimbursed
 * @property string|null $currency
 * @property string|null $reimbursed_on
 * @property string|null $observation
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class AbsenceSubsidyCase extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AbsenceSubsidyCaseFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const SUBJECT = 'absence_subsidy_case';

    protected $table = 'absence_subsidy_cases';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'pending_filing'];

    protected $casts = ['year' => 'integer', 'lock_version' => 'integer'];

    protected static function newFactory(): AbsenceSubsidyCaseFactory
    {
        return AbsenceSubsidyCaseFactory::new();
    }

    /** Fecha sin hora (AAAA-MM-DD) o null. */
    public function day(string $column): ?string
    {
        $v = $this->getRawOriginal($column, $this->getAttribute($column));

        return $v === null || $v === '' ? null : substr((string) $v, 0, 10);
    }
}
