<?php

namespace App\Modules\Imports\Infrastructure\Models;

use App\Modules\Imports\Database\Factories\TenantCountryPackFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla tenant_country_packs · Paquete de país instalado por la organización y su versión (IMP-4).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $country_pack_code
 * @property string $installed_version
 */
class TenantCountryPack extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantCountryPackFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tenant_country_packs';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): TenantCountryPackFactory
    {
        return TenantCountryPackFactory::new();
    }
}
