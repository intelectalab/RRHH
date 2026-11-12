<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\EmploymentCostDistributionFactory;
use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\Vigencia\HasValidity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ORG-4 · Distribución con vigencia de una relación laboral entre centros de costo (RN-ORG-06).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string|null $reason
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property int $lock_version
 * @property CarbonInterface $created_at
 */
class EmploymentCostDistribution extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<EmploymentCostDistributionFactory> */
    use HasFactory;

    use HasUuids;
    use HasValidity;

    protected $table = 'employment_cost_distributions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date'];

    /**
     * @return HasMany<EmploymentCostDistributionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EmploymentCostDistributionLine::class, 'employment_cost_distribution_id');
    }

    protected static function newFactory(): EmploymentCostDistributionFactory
    {
        return EmploymentCostDistributionFactory::new();
    }
}
