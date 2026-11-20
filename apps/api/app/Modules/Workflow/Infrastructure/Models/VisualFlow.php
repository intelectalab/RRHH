<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Modules\Workflow\Database\Factories\VisualFlowFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * WFL-6 · Flujo del constructor visual: una definición (workflow_definitions) de tipo «automation» con sus
 * versiones; la versión publicada guarda el grafo y materializa nodos (workflow_steps) y conexiones
 * (workflow_transitions).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $description
 * @property string $status
 * @property string|null $current_version_id
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VisualFlow extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VisualFlowFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'workflow_definitions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['lock_version' => 'integer'];

    protected static function booted(): void
    {
        static::addGlobalScope('visual_flow', fn (Builder $q) => $q->where('kind', 'automation'));
        static::creating(fn (VisualFlow $flow) => $flow->kind = 'automation');
    }

    /**
     * @return HasMany<ApprovalFlowVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ApprovalFlowVersion::class, 'workflow_definition_id');
    }

    protected static function newFactory(): VisualFlowFactory
    {
        return VisualFlowFactory::new();
    }
}
