<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CUM-9 · Responsable de una obligación de planilla en una empresa.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_entity_id
 * @property string $obligation_code
 * @property string $responsible_user_id
 */
class PayrollObligationAssignment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'payroll_obligation_assignments';

    protected $guarded = ['id', 'tenant_id'];
}
