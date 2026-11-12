<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\JobFamilyFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Familia de puestos (ORG-5): catálogo configurable que se archiva, no se borra.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class JobFamily extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<JobFamilyFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'job_families';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer'];

    protected static function newFactory(): JobFamilyFactory
    {
        return JobFamilyFactory::new();
    }
}
