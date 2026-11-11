<?php

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Database\Factories\AuditArchiveFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lectura de audit_archives (AUD-3 / AUD-5). Se escribe solo desde los servicios del módulo.
 */
class AuditArchive extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AuditArchiveFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_archives';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): AuditArchiveFactory
    {
        return AuditArchiveFactory::new();
    }
}
