<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * DOC-6 · Firmante de una solicitud de firma y su estado (pending, sent, viewed, signed, declined).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $signature_request_id
 * @property string|null $user_id
 * @property string|null $person_id
 * @property string $signer_name
 * @property string|null $signer_role
 * @property int $sort_order
 * @property string $status
 * @property CarbonInterface|null $viewed_at
 * @property CarbonInterface|null $signed_at
 * @property string|null $declined_reason
 * @property CarbonInterface|null $last_reminded_at
 * @property int $lock_version
 */
class SignatureRequestSigner extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'signature_request_signers';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'sort_order' => 'integer', 'viewed_at' => 'datetime', 'signed_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];
}
