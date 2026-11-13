<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-4 · Dirección de la persona; el texto viaja cifrado (address_enc, FieldCipher). Una dirección
 * nueva del mismo tipo deja la anterior como histórica (is_current = false).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $kind
 * @property string $address_enc
 * @property string|null $district_code
 * @property string|null $country_code
 * @property CarbonInterface|null $valid_from
 * @property bool $is_current
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PersonAddress extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'person_addresses';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['address_enc'];

    protected $casts = ['is_current' => 'boolean', 'valid_from' => 'date'];
}
