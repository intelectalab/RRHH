<?php

namespace App\Modules\Contracts\Infrastructure\Models;

use App\Modules\Contracts\Database\Factories\ContractFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla contracts · CON-2 · ME-11. Contrato de una relación laboral con vigencia (valid_from / valid_to);
 * una renovación es un contrato nuevo enlazado por renewal_of_contract_id.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $contract_type_id
 * @property string|null $number
 * @property string|null $objective_cause
 * @property Carbon|null $trial_period_ends_on
 * @property string|null $weekly_hours
 * @property string $work_modality
 * @property string $status
 * @property string|null $renewal_of_contract_id
 * @property string|null $document_id
 * @property int|null $accumulated_days
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $signed_at
 * @property string|null $signature_origin
 * @property string|null $approved_by_user_id
 * @property Carbon|null $approved_at
 * @property string|null $termination_reason
 * @property Carbon|null $probation_alerted_on
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Contract extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'contracts';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'trial_period_ends_on' => 'date',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'signed_at' => 'datetime',
        'approved_at' => 'datetime',
        'probation_alerted_on' => 'date',
        'accumulated_days' => 'integer',
        'lock_version' => 'integer',
    ];

    protected static function newFactory(): ContractFactory
    {
        return ContractFactory::new();
    }
}
