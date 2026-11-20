<?php

namespace App\Modules\Imports\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tabla import_templates · Mapeo de columnas guardado para reutilizarlo (IMP-1, IMP-2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity
 * @property string|null $name
 * @property array<string, mixed> $column_mapping
 * @property string|null $source_code IMP-7 · código del perfil de origen (null en un mapeo guardado común)
 * @property int $version
 * @property array<string, array<string, string>>|null $value_maps
 * @property array<string, string>|null $default_values
 * @property string|null $description
 * @property string|null $created_by_user_id
 */
class ImportTemplate extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'import_templates';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['column_mapping' => 'array', 'value_maps' => 'array', 'default_values' => 'array', 'is_system' => 'boolean', 'version' => 'integer'];
}
