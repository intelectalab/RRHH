<?php

namespace App\Modules\Certificates\Infrastructure\Models;

use App\Modules\Certificates\Database\Factories\CertificateRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ESS-4 · Solicitud de constancia del colaborador (certificate_requests).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $kind
 * @property string|null $purpose
 * @property string $status
 * @property string|null $document_id
 * @property Carbon|null $issued_at
 * @property string|null $requested_by_user_id
 * @property string|null $workflow_instance_id
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $rejection_reason
 * @property bool $auto_issued
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CertificateRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CertificateRequestFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'certificate_requests';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['status' => 'requested', 'auto_issued' => false];

    protected $casts = [
        'issued_at' => 'datetime', 'decided_at' => 'datetime', 'auto_issued' => 'boolean', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): CertificateRequestFactory
    {
        return CertificateRequestFactory::new();
    }
}
