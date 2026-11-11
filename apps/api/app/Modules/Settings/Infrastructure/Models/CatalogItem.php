<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\CatalogItemFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla catalog_items · CFG-10. Elemento propio de la organización. Un elemento no se elimina:
 * se archiva (is_active = falso) y deja de ofrecerse para registros nuevos (RN-CFG-09).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $catalog_id
 * @property string $code
 * @property string $label
 * @property array<string, string>|null $labels
 * @property int $sort_order
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Catalog|null $catalog
 */
class CatalogItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CatalogItemFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'catalog_items';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['labels' => 'array', 'metadata' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    /**
     * @return BelongsTo<Catalog, $this>
     */
    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    protected static function newFactory(): CatalogItemFactory
    {
        return CatalogItemFactory::new();
    }
}
