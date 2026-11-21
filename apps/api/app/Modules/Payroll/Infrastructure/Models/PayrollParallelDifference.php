<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PER-13 · Diferencia por trabajador y concepto entre el sistema anterior y el producto, con su clasificación.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payroll_parallel_comparison_id
 * @property string $employment_id
 * @property string $concept_code
 * @property string|null $legacy_amount
 * @property string|null $product_amount
 * @property string $difference
 * @property string $currency
 * @property string $classification
 * @property bool $explained
 * @property string|null $comment
 * @property string|null $explained_by_user_id
 * @property mixed $explained_at
 */
class PayrollParallelDifference extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const CLASSIFICATIONS = ['input', 'parameter', 'rule', 'rounding'];

    protected $table = 'payroll_parallel_differences';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['explained' => 'boolean'];
}
