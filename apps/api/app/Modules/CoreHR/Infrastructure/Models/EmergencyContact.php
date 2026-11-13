<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-4 · Contacto de emergencia de la persona; el teléfono viaja cifrado (phone_enc).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $full_name
 * @property string|null $relationship
 * @property string $phone_enc
 * @property int|null $sort_order
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class EmergencyContact extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'emergency_contacts';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['phone_enc'];

    protected $casts = ['sort_order' => 'integer'];
}
