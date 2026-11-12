<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\SavedViewFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla saved_views · UI-4. Vista guardada de una tabla (filtros, columnas y orden) de un usuario;
 * puede compartirse con la organización. Solo su dueño la cambia o la borra.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $owner_user_id
 * @property string $resource
 * @property string $name
 * @property array<string, mixed> $config
 * @property bool $is_shared
 * @property bool $is_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SavedView extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SavedViewFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'saved_views';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['config' => 'array', 'is_shared' => 'boolean', 'is_default' => 'boolean'];

    protected static function newFactory(): SavedViewFactory
    {
        return SavedViewFactory::new();
    }
}
