<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\HeadcountScenarioFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * POS-5 · Escenario de planificación de la dotación (copia de la dotación a base_as_of con sus cambios).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $headcount_plan_id
 * @property string $name
 * @property string $kind
 * @property bool $is_baseline
 * @property array<string, mixed>|null $assumptions
 * @property string|null $legal_entity_id
 * @property CarbonInterface $base_as_of
 * @property string $status
 * @property string|null $created_by_user_id
 * @property string|null $approved_by_user_id
 * @property CarbonInterface|null $approved_at
 * @property CarbonInterface|null $applied_at
 * @property array<string, mixed>|null $applied_summary
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class HeadcountScenario extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HeadcountScenarioFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'headcount_scenarios';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'is_baseline' => 'boolean', 'assumptions' => 'array', 'applied_summary' => 'array',
        'base_as_of' => 'date', 'approved_at' => 'datetime', 'applied_at' => 'datetime',
    ];

    protected static function newFactory(): HeadcountScenarioFactory
    {
        return HeadcountScenarioFactory::new();
    }
}
