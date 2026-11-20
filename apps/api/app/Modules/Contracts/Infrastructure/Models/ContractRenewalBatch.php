<?php

namespace App\Modules\Contracts\Infrastructure\Models;

use App\Modules\Contracts\Database\Factories\ContractRenewalBatchFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CON-5 · Lote de renovación masiva de contratos a plazo, con envío a firma en lote (E-08).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $name
 * @property string|null $legal_entity_id
 * @property string $status
 * @property CarbonInterface|null $new_end_date
 * @property string $created_by_user_id
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $completed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 */
class ContractRenewalBatch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractRenewalBatchFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'contract_renewal_batches';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'new_end_date' => 'date', 'sent_at' => 'datetime', 'completed_at' => 'datetime'];

    /** @return HasMany<ContractRenewalBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ContractRenewalBatchItem::class, 'contract_renewal_batch_id')->orderBy('created_at');
    }

    protected static function newFactory(): ContractRenewalBatchFactory
    {
        return ContractRenewalBatchFactory::new();
    }
}
