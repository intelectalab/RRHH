<?php

namespace App\Modules\CoreHR\Infrastructure\Models;

use App\Modules\CoreHR\Database\Factories\BankAccountFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * COL-8 · Cuenta de pago (haberes o CTS) de una relación laboral, con vigencia. El número y el código
 * interbancario viajan cifrados (FieldCipher); sin permiso de campo solo se ven los últimos dígitos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $employment_id
 * @property string $purpose
 * @property string $bank_code
 * @property string $account_type
 * @property string $account_number_enc
 * @property string|null $interbank_code_enc
 * @property string $currency
 * @property bool $is_active
 * @property string $status
 * @property string|null $requested_by_user_id
 * @property string|null $decided_by_user_id
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_to
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class BankAccount extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'person_bank_accounts';

    protected $guarded = ['id', 'tenant_id', 'lock_version'];

    protected $hidden = ['account_number_enc', 'interbank_code_enc'];

    protected $casts = [
        'is_active' => 'boolean', 'decided_at' => 'datetime', 'valid_from' => 'date', 'valid_to' => 'date', 'lock_version' => 'integer',
    ];

    protected static function newFactory(): BankAccountFactory
    {
        return BankAccountFactory::new();
    }
}
