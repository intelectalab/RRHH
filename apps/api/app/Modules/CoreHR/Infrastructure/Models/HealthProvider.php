<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\HealthProviderFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-7 · Entidad prestadora de salud o aseguradora con la que trabaja la organización (EPS, aseguradora).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $tax_id
 * @property CarbonInterface $created_at
 */
class HealthProvider extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HealthProviderFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'health_providers';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): HealthProviderFactory
    {
        return HealthProviderFactory::new();
    }
}
