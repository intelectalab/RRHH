<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Database\Factories\SupportAccessGrantFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * SAS-8 · Autorización temporal de la organización al soporte de GestiaHR («read_only» o «full»).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $granted_by_user_id
 * @property string $access_level
 */
class SupportAccessGrant extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SupportAccessGrantFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'support_access_grants';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function newFactory(): SupportAccessGrantFactory
    {
        return SupportAccessGrantFactory::new();
    }
}
