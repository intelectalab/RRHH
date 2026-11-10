<?php

namespace App\Modules\Audit\Infrastructure\Models;

use App\Modules\Audit\Database\Factories\SensitiveAccessLogFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Lectura de data_access_logs (solo inserción: no se actualiza ni se borra).
 */
class SensitiveAccessLog extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SensitiveAccessLogFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $table = 'data_access_logs';

    protected $guarded = ['id', 'tenant_id'];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('La auditoría es de solo inserción.'));
        static::deleting(static fn () => throw new LogicException('La auditoría es de solo inserción.'));
    }

    protected static function newFactory(): SensitiveAccessLogFactory
    {
        return SensitiveAccessLogFactory::new();
    }
}
