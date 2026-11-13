<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\PositionRequestFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * POS-3 · Solicitud de posición (ME-04): nueva, reemplazo o temporal, con motivo y costo estimado,
 * que pasa por el flujo de aprobación antes de crear sus posiciones.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $requested_by_user_id
 * @property string|null $legal_entity_id
 * @property string $org_unit_id
 * @property string $job_profile_id
 * @property string|null $site_id
 * @property string|null $title
 * @property string|null $cost_center_id
 * @property string|null $reports_to_position_id
 * @property string|null $salary_band_id
 * @property int $quantity
 * @property string $justification
 * @property string $kind
 * @property string|null $replaces_employment_id
 * @property string|null $estimated_cost
 * @property string|null $currency
 * @property string $status
 * @property string|null $workflow_instance_id
 * @property CarbonInterface|null $needed_by
 * @property bool $over_budget
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class PositionRequest extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PositionRequestFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'position_requests';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'quantity' => 'integer', 'over_budget' => 'boolean',
        'needed_by' => 'date', 'submitted_at' => 'datetime', 'decided_at' => 'datetime',
    ];

    protected static function newFactory(): PositionRequestFactory
    {
        return PositionRequestFactory::new();
    }
}
