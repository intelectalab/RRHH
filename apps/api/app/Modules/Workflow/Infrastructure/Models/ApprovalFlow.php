<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Modules\Workflow\Database\Factories\ApprovalFlowFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla workflow_definitions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $subject_type
 * @property string $status
 * @property string|null $current_version_id
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApprovalFlow extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ApprovalFlowFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'workflow_definitions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer'];

    /**
     * WFL-6 · La misma tabla guarda los flujos del constructor visual (kind automation): aquí solo los de aprobación.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('approval_flow', fn (Builder $q) => $q->where('kind', 'approval'));
    }

    /**
     * @return HasMany<ApprovalFlowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ApprovalFlowVersion::class, 'workflow_definition_id');
    }

    protected static function newFactory(): ApprovalFlowFactory
    {
        return ApprovalFlowFactory::new();
    }
}
