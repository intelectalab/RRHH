<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\JobCategoryFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Categoría laboral (ORG-5): el cuadro de categorías y funciones de la Ley 30709 (E-05).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property int|null $rank
 * @property string|null $description
 * @property string|null $functions
 * @property string|null $responsibility_level
 * @property string|null $requirements
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class JobCategory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<JobCategoryFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'job_categories';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'rank' => 'integer'];

    protected static function newFactory(): JobCategoryFactory
    {
        return JobCategoryFactory::new();
    }
}
