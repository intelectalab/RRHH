<?php

namespace App\Modules\Ai\Infrastructure\Models;

use App\Modules\Ai\Database\Factories\AiUseCaseSettingFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * IAR-10 · Activación de un caso de uso de IA en la organización (ai_use_case_settings). Se identifica en la API
 * por su caso de uso (help_assistant); los cambios pasan por AiUseCaseSettings, que los audita.
 *
 * @property string $id
 * @property string $use_case
 * @property bool $is_enabled
 */
class AiUseCaseSetting extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AiUseCaseSettingFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'ai_use_case_settings';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['is_enabled' => 'boolean'];

    protected static function newFactory(): AiUseCaseSettingFactory
    {
        return AiUseCaseSettingFactory::new();
    }
}
