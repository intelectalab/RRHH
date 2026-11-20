<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\LegalEntityAnnualFigureFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NOM-10 · Ventas netas y dotación promedio de una razón social por ejercicio.
 *
 * @property string $id
 * @property string $tenant_id
 */
class LegalEntityAnnualFigure extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LegalEntityAnnualFigureFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'legal_entity_annual_figures';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['fiscal_year' => 'integer'];

    protected static function newFactory(): LegalEntityAnnualFigureFactory
    {
        return LegalEntityAnnualFigureFactory::new();
    }
}
