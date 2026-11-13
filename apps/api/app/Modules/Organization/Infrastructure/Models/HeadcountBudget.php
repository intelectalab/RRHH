<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\HeadcountBudgetFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * POS-4 · Presupuesto de dotación de una unidad desde una fecha: plazas y costo mensual. Un solo
 * presupuesto vigente por unidad a la vez (restricción de exclusión en base).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $org_unit_id
 * @property int $budgeted_positions
 * @property string|null $monthly_cost
 * @property string|null $currency
 * @property string|null $notes
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class HeadcountBudget extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HeadcountBudgetFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'headcount_budgets';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'lock_version' => 'integer', 'budgeted_positions' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date',
    ];

    protected static function newFactory(): HeadcountBudgetFactory
    {
        return HeadcountBudgetFactory::new();
    }
}
