<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Simplificación para la calculadora de referencia. En el producto estos datos vienen de employments,
 * employment_compensations, employment_labor_regimes y employment_pension_affiliations, con vigencia,
 * y el modelo lleva el trait BelongsToTenant (docs/02).
 *
 * @property string $base_salary
 * @property ?string $construction_category  operario, oficial o peón (construcción civil)
 * @property bool $agrarian_prorated_benefits  el colaborador agrario eligió recibir gratificación y CTS prorrateadas
 * @property ?string $agrarian_tier  tramo de EsSalud agrario de la razón social (A o B)
 */
final class Employee extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'full_name', 'base_salary', 'has_family_allowance', 'labor_regime_code',
        'health_scheme', 'pension_system', 'pension_fund_code', 'commission_scheme', 'hire_date', 'termination_date',
        'construction_category', 'agrarian_prorated_benefits', 'agrarian_tier'];

    protected function casts(): array
    {
        return ['has_family_allowance' => 'boolean', 'agrarian_prorated_benefits' => 'boolean', 'hire_date' => 'immutable_date', 'termination_date' => 'immutable_date'];
    }

    public function laborRegime(): BelongsTo
    {
        return $this->belongsTo(LaborRegime::class, 'labor_regime_code', 'code');
    }

    public function pensionFund(): BelongsTo
    {
        return $this->belongsTo(PensionFund::class, 'pension_fund_code', 'code');
    }
}
