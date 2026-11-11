<?php

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Database\Factories\AuditIntegrityCheckFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Lectura de audit_integrity_checks (AUD-3 / AUD-5). Se escribe solo desde los servicios del módulo.
 */
class AuditIntegrityCheck extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AuditIntegrityCheckFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_integrity_checks';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): AuditIntegrityCheckFactory
    {
        return AuditIntegrityCheckFactory::new();
    }
}
