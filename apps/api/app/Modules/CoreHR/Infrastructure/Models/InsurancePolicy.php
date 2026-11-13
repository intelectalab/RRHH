<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\InsurancePolicyFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-7 · Póliza del empleador por empresa: Vida Ley (life), SCTR salud (risk_health), SCTR pensión
 * (risk_pension), plan de salud u otra.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $kind
 * @property string $insurer
 * @property string $policy_number
 * @property CarbonInterface $starts_on
 * @property CarbonInterface|null $ends_on
 * @property CarbonInterface|null $registered_with_authority_on
 * @property string|null $health_provider_id
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class InsurancePolicy extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InsurancePolicyFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'insurance_policies';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'registered_with_authority_on' => 'date', 'lock_version' => 'integer'];

    protected static function newFactory(): InsurancePolicyFactory
    {
        return InsurancePolicyFactory::new();
    }
}
