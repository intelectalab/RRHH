<?php

namespace App\Modules\Contracts\Infrastructure\Models;

use App\Modules\Contracts\Database\Factories\ContractTypeFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla contract_types · CON-1. Los tipos del paquete de país (is_system) son de solo lectura;
 * la organización agrega los suyos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $country_code
 * @property string $category
 * @property string|null $modality_code
 * @property string|null $max_duration_parameter_code
 * @property string|null $trial_period_parameter_code
 * @property bool $requires_objective_cause
 * @property bool $requires_end_date
 * @property bool $is_system
 * @property string|null $document_template_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContractType extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractTypeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const CATEGORIES = ['indefinite', 'fixed_term', 'part_time', 'internship', 'other'];

    protected $table = 'contract_types';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'requires_objective_cause' => 'boolean',
        'requires_end_date' => 'boolean',
        'is_system' => 'boolean',
    ];

    protected static function newFactory(): ContractTypeFactory
    {
        return ContractTypeFactory::new();
    }
}
