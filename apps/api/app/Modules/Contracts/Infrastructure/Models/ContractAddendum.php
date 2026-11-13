<?php

namespace App\Modules\Contracts\Infrastructure\Models;

use App\Modules\Contracts\Database\Factories\ContractAddendumFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tabla contract_amendments · CON-3. Adenda al contrato: cambio pactado con fecha que, firmado, se aplica
 * con vigencia (RN-CON-08). El contrato de la API la expone como ContractAddendum (/contract-addenda).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property string|null $number
 * @property string $kind
 * @property Carbon $effective_on
 * @property string $description
 * @property string|null $document_id
 * @property string $status
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $applied_refs
 * @property Carbon|null $signed_at
 * @property string|null $signature_origin
 * @property string|null $cancellation_reason
 * @property int $lock_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContractAddendum extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractAddendumFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const KINDS = ['salary', 'position', 'schedule', 'extension', 'modality', 'other'];

    protected $table = 'contract_amendments';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'effective_on' => 'date',
        'signed_at' => 'datetime',
        'changes' => 'array',
        'applied_refs' => 'array',
        'lock_version' => 'integer',
    ];

    protected static function newFactory(): ContractAddendumFactory
    {
        return ContractAddendumFactory::new();
    }
}
