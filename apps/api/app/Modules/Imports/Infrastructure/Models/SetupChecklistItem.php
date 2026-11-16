<?php

namespace App\Modules\Imports\Infrastructure\Models;

use App\Modules\Imports\Database\Factories\SetupChecklistItemFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla setup_checklist_items · Avance guardado de un paso del asistente de primeros pasos (IMP-3).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $status
 */
class SetupChecklistItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SetupChecklistItemFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'setup_checklist_items';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): SetupChecklistItemFactory
    {
        return SetupChecklistItemFactory::new();
    }
}
