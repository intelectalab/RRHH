<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\InsuranceCoverageFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * COL-7 · Cobertura de un trabajador en una póliza del empleador (insurance_policy_members) y su periodo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $insurance_policy_id
 * @property string $employment_id
 * @property CarbonInterface $covered_from
 * @property CarbonInterface|null $covered_to
 * @property string|null $plan_code
 * @property CarbonInterface $created_at
 */
class InsuranceCoverage extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InsuranceCoverageFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'insurance_policy_members';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['covered_from' => 'date', 'covered_to' => 'date'];

    protected static function newFactory(): InsuranceCoverageFactory
    {
        return InsuranceCoverageFactory::new();
    }
}
