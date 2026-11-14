<?php

namespace App\Modules\Vacations\Infrastructure\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla vacation_ledger_entries · VAC-2 · RN-VAC-02. Libro de solo inserción: la base rechaza UPDATE y
 * DELETE. Un error se corrige con un contraasiento (reversal) que apunta al movimiento revertido.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employment_id
 * @property string|null $vacation_accrual_period_id
 * @property string $entry_type
 * @property string $days
 * @property string $effective_on
 * @property string|null $vacation_request_id
 * @property string|null $reverses_entry_id
 * @property string|null $reason
 * @property string|null $created_by_user_id
 * @property string|null $evidence
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 */
class VacationLedgerEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'vacation_ledger_entries';

    protected $guarded = ['id', 'tenant_id'];
}
