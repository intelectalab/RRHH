<?php

namespace App\Modules\Ai\Infrastructure\Models;

use App\Modules\Ai\Database\Factories\AiInteractionFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * IAR-2 · Fila de la bitácora de IA (ai_interactions, solo inserción). Los casos de uso escriben por
 * AiUsageLog; el modelo existe para las pruebas de aislamiento y las fábricas.
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $use_case
 */
class AiInteraction extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AiInteractionFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'ai_interactions';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): AiInteractionFactory
    {
        return AiInteractionFactory::new();
    }
}
