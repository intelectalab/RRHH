<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollGroupFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PER-1 · Grupo de planilla: empresa, frecuencia de pago, régimen y moneda. Sin miembros explícitos, el grupo
 * incluye a todas las relaciones laborales de su empresa.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $legal_entity_id
 * @property string $pay_frequency
 * @property string|null $labor_regime_code
 * @property string $currency
 * @property int|null $payment_day
 * @property int|null $cutoff_day
 * @property string $status
 * @property int $lock_version
 */
class PayrollGroup extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollGroupFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'payroll_groups';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'active', 'pay_frequency' => 'monthly'];

    protected $casts = ['payment_day' => 'integer', 'cutoff_day' => 'integer', 'lock_version' => 'integer'];

    protected static function newFactory(): PayrollGroupFactory
    {
        return PayrollGroupFactory::new();
    }
}
