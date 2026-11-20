<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\SettlementFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * LIQ-1 · Liquidación de beneficios sociales al cese (ME-23).
 *
 * El caso de uso trabaja con el constructor de consultas; el modelo sirve al alcance por organización y a las fábricas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $termination_date
 * @property string $status
 * @property string $currency
 */
class Settlement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<SettlementFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'settlements';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): SettlementFactory
    {
        return SettlementFactory::new();
    }
}
