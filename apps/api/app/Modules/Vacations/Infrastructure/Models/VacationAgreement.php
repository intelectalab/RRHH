<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Modules\Vacations\Database\Factories\VacationAgreementFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * VAC-6 · Acuerdo escrito de fraccionamiento, adelanto o venta de vacaciones.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $kind
 * @property string|null $vacation_accrual_period_id
 * @property string|null $days
 * @property string $effective_on
 * @property string $status
 * @property string|null $document_id
 * @property CarbonInterface|null $signed_at
 * @property string|null $signature_origin
 * @property string|null $payroll_concept_code
 * @property string|null $ledger_entry_id
 * @property string|null $created_by_user_id
 * @property string|null $cancel_reason
 * @property string|null $notes
 * @property int $lock_version
 */
class VacationAgreement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VacationAgreementFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const KINDS = ['fractionation', 'advance', 'sale'];

    protected $table = 'vacation_agreements';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'draft'];

    protected $casts = ['signed_at' => 'datetime', 'lock_version' => 'integer'];

    /** Fecha laboral sin hora (AAAA-MM-DD). */
    public function day(string $column): string
    {
        return substr((string) $this->getRawOriginal($column, $this->getAttribute($column)), 0, 10);
    }

    protected static function newFactory(): VacationAgreementFactory
    {
        return VacationAgreementFactory::new();
    }
}
