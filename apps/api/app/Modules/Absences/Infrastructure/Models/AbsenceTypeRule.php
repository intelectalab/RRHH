<?php

namespace App\Modules\Absences\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use App\Shared\Vigencia\HasValidity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tabla absence_type_rules · AUS-1. Reglas de un tipo con vigencia: se cambian con
 * EffectiveDated::change (cierra la fila vigente e inserta otra), nunca en sitio.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $absence_type_id
 * @property bool $is_paid
 * @property bool $affects_attendance
 * @property string $unit
 * @property string|null $max_quantity
 * @property string $max_period
 * @property int|null $min_notice_days
 * @property bool $requires_document
 * @property int|null $document_grace_days
 * @property string $requestable_by
 * @property string|null $approval_workflow_definition_id
 * @property string|null $payroll_concept_code
 * @property string|null $statutory_suspension_code
 * @property string $subsidy_kind
 * @property bool $counts_as_worked_for_benefits
 * @property list<string>|null $legal_entity_ids
 * @property list<string>|null $labor_regime_codes
 * @property string $valid_from
 * @property string|null $valid_to
 */
class AbsenceTypeRule extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use HasValidity;

    protected $table = 'absence_type_rules';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = [
        'is_paid' => 'boolean',
        'affects_attendance' => 'boolean',
        'requires_document' => 'boolean',
        'counts_as_worked_for_benefits' => 'boolean',
        'min_notice_days' => 'integer',
        'document_grace_days' => 'integer',
        'legal_entity_ids' => 'array',
        'labor_regime_codes' => 'array',
    ];
}
