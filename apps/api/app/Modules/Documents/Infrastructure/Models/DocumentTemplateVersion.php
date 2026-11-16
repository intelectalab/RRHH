<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla document_template_versions · DOC-4. Sin published_at es el borrador que se edita; publicada queda fija y
 * los documentos generados conservan la suya (RN-DOC-11).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_template_id
 * @property int $version
 * @property string $body
 * @property list<string>|null $variables
 * @property array<string, mixed>|null $page_settings
 * @property Carbon|null $published_at
 * @property string|null $created_by_user_id
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DocumentTemplateVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'document_template_versions';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['version' => 'integer', 'variables' => 'array', 'page_settings' => 'array', 'published_at' => 'datetime'];
}
