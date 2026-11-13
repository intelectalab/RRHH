<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\CompensationChangeFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HIS-3 · Cambio de compensación: un movimiento de personal de tipo salary_change. Guarda la propuesta
 * (sueldo, periodicidad, componentes, fecha y motivo) mientras se aprueba; al aplicarse crea la fila de
 * employment_compensations y queda enlazado a ella (RN-HIS-08).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string $kind
 * @property CarbonInterface $effective_on
 * @property string|null $reason
 * @property string $status
 * @property string|null $requested_by_user_id
 * @property CarbonInterface|null $applied_at
 * @property array<string, mixed>|null $payload
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class CompensationChange extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CompensationChangeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const KIND = 'salary_change';

    protected $table = 'personnel_actions';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $attributes = ['kind' => self::KIND];

    protected $casts = [
        'effective_on' => 'date', 'applied_at' => 'datetime', 'payload' => 'array', 'lock_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('salary_change', fn (Builder $q) => $q->where('personnel_actions.kind', self::KIND));
    }

    protected static function newFactory(): CompensationChangeFactory
    {
        return CompensationChangeFactory::new();
    }
}
