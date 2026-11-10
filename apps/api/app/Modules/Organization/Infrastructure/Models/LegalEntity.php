<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Modules\Organization\Database\Factories\LegalEntityFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Empresa o razón social de la organización (C-01); empleador de las relaciones laborales.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $country_code
 * @property string $tax_id
 * @property string $currency
 * @property string $timezone
 * @property string $status
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class LegalEntity extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LegalEntityFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'legal_entities';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer', 'started_operations_on' => 'date'];

    /**
     * @return HasMany<LegalEntityRegime, $this>
     */
    public function regimes(): HasMany
    {
        return $this->hasMany(LegalEntityRegime::class, 'legal_entity_id');
    }

    public function currentRegime(?string $asOf = null): ?LegalEntityRegime
    {
        $date = $asOf ?? now()->toDateString();

        /** @var LegalEntityRegime|null */
        return $this->regimes()
            ->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->first();
    }

    protected static function newFactory(): LegalEntityFactory
    {
        return LegalEntityFactory::new();
    }
}
