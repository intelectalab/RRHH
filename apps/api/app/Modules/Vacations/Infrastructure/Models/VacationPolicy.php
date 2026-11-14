<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Modules\Vacations\Database\Factories\VacationPolicyFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla vacation_policies · VAC-1. Identidad y alcance de la política; las reglas están en
 * vacation_policy_rules con vigencia.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $legal_entity_id
 * @property string|null $labor_regime_code
 * @property string|null $job_category_id
 * @property string $status
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VacationPolicy extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VacationPolicyFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'vacation_policies';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['status' => 'active'];

    protected $casts = ['lock_version' => 'integer'];

    protected static function newFactory(): VacationPolicyFactory
    {
        return VacationPolicyFactory::new();
    }
}
