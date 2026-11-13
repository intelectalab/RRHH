<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-1 · Documento de identidad: el número viaja cifrado (number_enc, FieldCipher) y se busca por su
 * huella (number_hash, BlindIndex). Un documento identifica a una sola persona por organización.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $doc_type
 * @property string $issuing_country
 * @property string $number_enc
 * @property string $number_hash
 * @property CarbonInterface|null $expires_on
 * @property bool $is_primary
 */
class PersonDocument extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'person_documents';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['number_enc', 'number_hash'];

    protected $casts = ['is_primary' => 'boolean', 'expires_on' => 'date'];
}
