<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\ProfileChangeRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * COL-4 · RN-COL-06 · Cambio de datos personales propuesto por el propio colaborador. `changes` guarda los
 * valores cifrados ({"enc": …}) y la lista de campos aplicados de inmediato y pendientes de RRHH.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $requested_by_user_id
 * @property array<string, mixed> $changes
 * @property string $status
 * @property string|null $reviewed_by_user_id
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $rejection_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class ProfileChangeRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProfileChangeRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'profile_change_requests';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['changes' => 'array', 'reviewed_at' => 'datetime', 'lock_version' => 'integer'];

    protected static function newFactory(): ProfileChangeRequestFactory
    {
        return ProfileChangeRequestFactory::new();
    }
}
