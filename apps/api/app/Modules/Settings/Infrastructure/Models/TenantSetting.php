<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\TenantSettingFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla tenant_settings (valor de un parámetro con ámbito y vigencia). Se escribe solo con
 * App\Shared\Settings\ParameterResolver; el modelo existe para las pruebas de aislamiento.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $parameter_code
 */
class TenantSetting extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'tenant_settings';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['value' => 'array'];

    protected static function newFactory(): TenantSettingFactory
    {
        return TenantSettingFactory::new();
    }
}
