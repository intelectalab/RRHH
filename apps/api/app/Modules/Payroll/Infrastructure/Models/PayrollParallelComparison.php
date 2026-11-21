<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PER-13 · Comparación de una corrida del periodo con el resultado del sistema anterior (abierta o lista).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payroll_period_id
 * @property string $payroll_run_id
 * @property string|null $legacy_system
 * @property string $tolerance
 * @property string $currency
 * @property string $status
 * @property array<string, mixed>|null $summary
 * @property string|null $report_document_id
 * @property string|null $created_by_user_id
 * @property string|null $ready_by_user_id
 * @property mixed $ready_at
 * @property string|null $ready_note
 * @property mixed $created_at
 */
class PayrollParallelComparison extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'payroll_parallel_comparisons';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['summary' => 'array', 'lock_version' => 'integer'];
}
