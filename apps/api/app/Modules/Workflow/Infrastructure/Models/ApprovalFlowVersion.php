<?php

namespace App\Modules\Workflow\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla workflow_definition_versions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_definition_id
 * @property int $version
 * @property array<string, mixed> $graph
 * @property Carbon|null $published_at
 * @property string|null $published_by_user_id
 */
class ApprovalFlowVersion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'workflow_definition_versions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $casts = ['graph' => 'array', 'published_at' => 'datetime', 'version' => 'integer'];
}
