<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\HealthRegimeFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * COL-7 · Régimen laboral y de salud del trabajador con vigencia (employment_labor_regimes, C-05): régimen
 * de salud (EsSalud, EPS con su plan, SIS u otro) y entidad prestadora.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string|null $labor_regime_code
 * @property string $health_scheme
 * @property string|null $health_provider_id
 * @property string|null $health_plan_code
 * @property bool $has_risk_insurance
 * @property bool $has_family_allowance
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 */
class HealthRegime extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HealthRegimeFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'employment_labor_regimes';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['has_risk_insurance' => 'boolean', 'has_family_allowance' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date'];

    protected static function newFactory(): HealthRegimeFactory
    {
        return HealthRegimeFactory::new();
    }
}
