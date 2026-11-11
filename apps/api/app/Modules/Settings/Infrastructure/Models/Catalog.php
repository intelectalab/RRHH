<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\CatalogFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla catalogs · CFG-10. Catálogo propio de la organización, o la extensión de un catálogo
 * maestro extensible (mismo código que el maestro; is_system = verdadero en ese caso).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Catalog extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CatalogFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'catalogs';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['is_system' => 'boolean'];

    protected static function newFactory(): CatalogFactory
    {
        return CatalogFactory::new();
    }
}
