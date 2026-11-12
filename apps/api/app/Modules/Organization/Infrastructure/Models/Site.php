<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\SiteFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ORG-2 · Sede, sucursal o ubicación física; base de feriados, geocerca de marcación y centro de riesgo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $legal_entity_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $address
 * @property string|null $district_code
 * @property string|null $country_code
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int|null $geofence_radius_m
 * @property string|null $timezone
 * @property string|null $establishment_code
 * @property string|null $holiday_calendar_id
 * @property bool $is_risk_site
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Site extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const KINDS = ['headquarters', 'branch', 'plant', 'store', 'site', 'remote'];

    protected $table = 'sites';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'geofence_radius_m' => 'integer', 'is_risk_site' => 'boolean'];

    protected static function newFactory(): SiteFactory
    {
        return SiteFactory::new();
    }
}
