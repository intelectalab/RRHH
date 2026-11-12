<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\JobLevelFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Nivel jerárquico del catálogo de puestos (ORG-5); rank ordena de menor a mayor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property int $rank
 * @property string|null $description
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class JobLevel extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<JobLevelFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'job_levels';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'rank' => 'integer'];

    protected static function newFactory(): JobLevelFactory
    {
        return JobLevelFactory::new();
    }
}
