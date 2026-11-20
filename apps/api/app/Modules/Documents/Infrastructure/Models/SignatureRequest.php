<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Modules\Documents\Database\Factories\SignatureRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DOC-6 · Solicitud de firma de una versión de un documento (C-12): firmantes, orden y nivel de firma.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property string $document_version_id
 * @property string|null $title
 * @property string $signing_order
 * @property string $signature_level
 * @property string $provider
 * @property string|null $provider_envelope_id
 * @property string $status
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $expires_at
 * @property string|null $requested_by_user_id
 * @property string|null $final_file_id
 * @property string|null $final_hash
 * @property string|null $cancellation_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class SignatureRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SignatureRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'signature_requests';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'sent_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    /** @return HasMany<SignatureRequestSigner, $this> */
    public function signers(): HasMany
    {
        return $this->hasMany(SignatureRequestSigner::class, 'signature_request_id')->orderBy('sort_order')->orderBy('created_at');
    }

    protected static function newFactory(): SignatureRequestFactory
    {
        return SignatureRequestFactory::new();
    }
}
