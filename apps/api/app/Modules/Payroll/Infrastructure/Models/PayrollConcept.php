<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Modules\Payroll\Database\Factories\PayrollConceptFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CPT-1 · Concepto de planilla de la organización. El servicio PayrollConcepts lo administra con consultas
 * directas; el modelo sirve a la siembra de datos de prueba (aislamiento entre organizaciones).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string $status
 */
class PayrollConcept extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PayrollConceptFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'payroll_concepts';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected static function newFactory(): PayrollConceptFactory
    {
        return PayrollConceptFactory::new();
    }
}
