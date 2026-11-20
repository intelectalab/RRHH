<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\ProfitSharingDistributionFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BSO-3 · Distribución anual de utilidades por empresa (renta, porcentaje de la actividad, reparto y estado).
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $status
 * @property string $legal_entity_id
 * @property int $fiscal_year
 * @property string $activity
 * @property string $currency
 * @property string|null $net_income_before_tax
 * @property string|null $participation_rate
 * @property string|null $total_amount
 * @property string|null $total_days_worked
 * @property string|null $total_remunerations
 * @property string|null $excess_amount
 * @property int|null $workers_count
 * @property string|null $warnings
 * @property string|null $calculated_at
 */
class ProfitSharingDistribution extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProfitSharingDistributionFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'profit_sharing_distributions';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): ProfitSharingDistributionFactory
    {
        return ProfitSharingDistributionFactory::new();
    }
}
