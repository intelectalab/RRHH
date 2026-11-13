<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\PensionAffiliationFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * COL-7 · Afiliación previsional con vigencia (sistema, administradora y tipo de comisión). El código de
 * afiliado (CUSPP) viaja cifrado.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $pension_system
 * @property string|null $pension_fund_code
 * @property string|null $affiliate_code_enc
 * @property string|null $commission_scheme
 * @property CarbonInterface|null $affiliated_on
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property CarbonInterface $created_at
 */
class PensionAffiliation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PensionAffiliationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'employment_pension_affiliations';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $hidden = ['affiliate_code_enc'];

    protected $casts = ['affiliated_on' => 'date', 'valid_from' => 'date', 'valid_to' => 'date'];

    protected static function newFactory(): PensionAffiliationFactory
    {
        return PensionAffiliationFactory::new();
    }
}
