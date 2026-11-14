<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla document_versions · DOC-2 · RN-DOC-01. Inmutable: una versión nueva se agrega, nunca reemplaza a otra.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $version
 * @property string $stored_file_id
 * @property string $content_hash
 * @property string|null $uploaded_by_user_id
 * @property string|null $notes
 * @property string|null $issued_on
 * @property string|null $expires_on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'document_versions';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['version' => 'integer'];
}
