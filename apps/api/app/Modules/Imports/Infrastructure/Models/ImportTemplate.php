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
 * @property array<string, string> $column_mapping
 */
class ImportTemplate extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $table = 'import_templates';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['column_mapping' => 'array', 'is_system' => 'boolean'];
}
