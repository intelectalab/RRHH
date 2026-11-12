<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ORG-5 · Versión inalterable de la descripción de un puesto (solo inserción: la base rechaza
 * UPDATE y DELETE).
 *
 * @property string $id
 * @property string $job_profile_id
 * @property int $version
 * @property string $name
 * @property string|null $mission
 * @property string|null $functions
 * @property string|null $requirements
 * @property string|null $change_reason
 * @property string|null $changed_by_user_id
 * @property CarbonInterface $created_at
 */
class JobProfileVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'job_profile_versions';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['version' => 'integer'];
}
