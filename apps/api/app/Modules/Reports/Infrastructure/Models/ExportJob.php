<?php

namespace App\Modules\Reports\Infrastructure\Models;

use App\Modules\Reports\Database\Factories\ExportJobFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla export_jobs (SEG-12).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource
 * @property string $format
 * @property string $requested_by_user_id
 * @property string $status
 * @property int|null $row_count
 */
class ExportJob extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExportJobFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'export_jobs';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['filters' => 'array', 'columns' => 'array', 'contains_sensitive' => 'boolean', 'expires_at' => 'datetime'];

    protected static function newFactory(): ExportJobFactory
    {
        return ExportJobFactory::new();
    }
}
