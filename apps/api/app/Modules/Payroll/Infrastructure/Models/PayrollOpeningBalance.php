<?php

namespace App\Modules\Payroll\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * PER-12 · Acumulado del ejercicio traído del sistema anterior: saldo de apertura por trabajador, con fecha de
 * corte y sustento. Nunca es una boleta: lo leen la proyección de quinta y los beneficios del semestre en curso.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property int $fiscal_year
 * @property string $cutoff_date
 * @property int|null $period_month
 * @property string $kind
 * @property int|null $semester
 * @property string|null $concept_code
 * @property string $amount
 * @property string $currency
 * @property string $evidence
 * @property string|null $import_job_id
 * @property string|null $created_by_user_id
 */
class PayrollOpeningBalance extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    public const KINDS = ['fifth_income', 'fifth_withheld', 'pension_contribution', 'health_contribution', 'gratification_base', 'cts_base', 'cts_deposit'];

    protected $table = 'payroll_opening_balances';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['fiscal_year' => 'integer', 'period_month' => 'integer', 'semester' => 'integer', 'lock_version' => 'integer'];
}
