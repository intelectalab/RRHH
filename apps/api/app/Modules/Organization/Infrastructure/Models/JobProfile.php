<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\JobProfileFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Puesto de catálogo (C-02, ORG-5), p. ej. «Analista Contable»; no es una plaza: muchas posiciones lo usan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $job_family_id
 * @property string|null $job_level_id
 * @property string|null $job_category_id
 * @property string|null $mission
 * @property string|null $functions
 * @property string|null $requirements
 * @property string|null $occupation_code
 * @property bool $is_risk_job
 * @property string $trust_classification
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class JobProfile extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<JobProfileFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'job_profiles';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'is_risk_job' => 'boolean'];

    protected static function newFactory(): JobProfileFactory
    {
        return JobProfileFactory::new();
    }
}
