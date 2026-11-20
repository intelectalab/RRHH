<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollRunLineFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NOM-6 · Línea de cálculo por concepto con su traza.
 *
 * @property string $id
 * @property string $tenant_id
 */
class PayrollRunLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollRunLineFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payroll_run_lines';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['trace' => 'array'];

    protected static function newFactory(): PayrollRunLineFactory
    {
        return PayrollRunLineFactory::new();
    }
}
