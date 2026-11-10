<?php

namespace App\Modules\Organization\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Régimen laboral de la empresa con vigencia (regla 2: cambiar es cerrar e insertar).
 *
 * @property string $id
 * @property string $legal_entity_id
 * @property string $labor_regime_code
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 */
class LegalEntityRegime extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'legal_entity_regimes';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['valid_from' => 'date', 'valid_to' => 'date'];
}
