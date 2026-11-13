<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\CompensationFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * HIS-3 · Compensación con vigencia de una relación laboral (C-03): sueldo base, moneda, periodicidad y
 * base de cálculo. Es la única fuente del sueldo para todos los módulos (C-11). Nunca se edita en sitio:
 * un cambio cierra la fila vigente el día anterior e inserta otra (RN-HIS-01).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $base_salary
 * @property string $currency
 * @property string $pay_frequency
 * @property string $salary_basis
 * @property bool $is_integral_salary
 * @property string|null $change_reason
 * @property string|null $salary_band_id
 * @property string|null $personnel_action_id
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property int $lock_version
 */
class Compensation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CompensationFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'employment_compensations';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'is_integral_salary' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): CompensationFactory
    {
        return CompensationFactory::new();
    }
}
