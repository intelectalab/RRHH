<?php

namespace App\Modules\Contracts\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CON-5 · Contrato dentro de un lote de renovación: decisión, control del tope acumulado y contrato sucesor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_renewal_batch_id
 * @property string $contract_id
 * @property string $decision
 * @property CarbonInterface|null $new_valid_to
 * @property string|null $new_contract_id
 * @property int|null $accumulated_days_after
 * @property bool|null $exceeds_limit
 * @property string|null $notes
 */
class ContractRenewalBatchItem extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'contract_renewal_batch_items';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['new_valid_to' => 'date', 'accumulated_days_after' => 'integer', 'exceeds_limit' => 'boolean'];
}
