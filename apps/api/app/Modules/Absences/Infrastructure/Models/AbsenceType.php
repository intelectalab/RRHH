<?php

namespace App\Modules\Absences\Infrastructure\Models;

use App\Modules\Absences\Database\Factories\AbsenceTypeFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla absence_types · AUS-1. Identidad del tipo; las reglas están en absence_type_rules con vigencia.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string|null $country_code
 * @property bool $is_system
 * @property bool $is_confidential
 * @property string|null $legal_duration_parameter_code
 * @property string $status
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AbsenceType extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AbsenceTypeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const CATEGORIES = ['medical_leave', 'maternity', 'paternity', 'personal', 'bereavement', 'union', 'compensatory', 'remote_work', 'unpaid', 'suspension', 'other'];

    protected $table = 'absence_types';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'active'];

    protected $casts = [
        'is_system' => 'boolean',
        'is_confidential' => 'boolean',
        'lock_version' => 'integer',
    ];

    protected static function newFactory(): AbsenceTypeFactory
    {
        return AbsenceTypeFactory::new();
    }
}
