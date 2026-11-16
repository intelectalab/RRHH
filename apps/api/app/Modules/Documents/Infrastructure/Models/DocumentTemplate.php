<?php

namespace App\Modules\Documents\Infrastructure\Models;

use App\Modules\Documents\Database\Factories\DocumentTemplateFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla document_templates · DOC-4. Plantilla por tipo documental y empresa; current_version_id es la versión
 * publicada con la que se genera (active = publicada, inactive = retirada).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $document_category_id
 * @property string|null $legal_entity_id
 * @property string $kind
 * @property string $locale
 * @property string|null $country_code
 * @property string|null $current_version_id
 * @property bool $is_system
 * @property string $status
 * @property int $lock_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class DocumentTemplate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DocumentTemplateFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'document_templates';

    protected $guarded = ['id', 'tenant_id', 'lock_version', 'status'];

    protected $attributes = ['status' => 'draft', 'locale' => 'es', 'is_system' => false];

    protected $casts = ['is_system' => 'boolean', 'lock_version' => 'integer'];

    protected static function newFactory(): DocumentTemplateFactory
    {
        return DocumentTemplateFactory::new();
    }
}
