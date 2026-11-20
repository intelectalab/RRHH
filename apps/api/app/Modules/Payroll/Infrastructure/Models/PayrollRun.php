<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollRunFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NOM-8 · Corrida de cálculo de un periodo con su avance (se usa por servicios; el modelo existe para las fábricas de prueba).
 *
 * @property string $id
 * @property string $tenant_id
 */
class PayrollRun extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payroll_runs';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['scope' => 'array', 'parameters_snapshot' => 'array', 'totals' => 'array'];

    protected static function newFactory(): PayrollRunFactory
    {
        return PayrollRunFactory::new();
    }
}
