<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\PositionFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * POS-1 · Posición o plaza (C-02): una silla concreta con puesto de catálogo, unidad, sede, centro de
 * costo, posición jefe y banda de referencia. Varias posiciones comparten el mismo puesto; una posición
 * admite un ocupante, o tantos como `seats` si es compartida (RN-POS-02).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string $job_profile_id
 * @property string $org_unit_id
 * @property string|null $site_id
 * @property string $legal_entity_id
 * @property string|null $reports_to_position_id
 * @property string|null $cost_center_id
 * @property string|null $salary_band_id
 * @property string $status
 * @property bool $is_critical
 * @property string $fte
 * @property bool $is_shared
 * @property int $seats
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property string|null $budgeted_cost
 * @property string|null $currency
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class Position extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PositionFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'positions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'seats' => 'integer', 'is_shared' => 'boolean', 'is_critical' => 'boolean',
        'valid_from' => 'date', 'valid_to' => 'date',
    ];

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
