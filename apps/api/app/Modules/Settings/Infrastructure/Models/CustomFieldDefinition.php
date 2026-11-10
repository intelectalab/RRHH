<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\CustomFieldDefinitionFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla custom_field_definitions · CFG-4. Desactivar = borrado lógico: los valores se conservan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity_type
 * @property string $key
 * @property string $label
 * @property string $data_type
 * @property array<int, string>|null $options
 * @property bool $is_required
 * @property bool $is_sensitive
 * @property string|null $visibility_permission_code
 * @property int $sort_order
 * @property string|null $section
 * @property int $lock_version
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CustomFieldDefinition extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CustomFieldDefinitionFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'custom_field_definitions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['options' => 'array', 'is_required' => 'boolean', 'is_sensitive' => 'boolean', 'sort_order' => 'integer', 'lock_version' => 'integer'];

    protected static function newFactory(): CustomFieldDefinitionFactory
    {
        return CustomFieldDefinitionFactory::new();
    }
}
