<?php

namespace App\Modules\CoreHR\Infrastructure\Labor;

use App\Modules\CoreHR\Infrastructure\Models\BankAccount;
use App\Shared\Labor\PaymentAccounts;
use App\Shared\Security\FieldCipher;

/**
 * PER-10 · Cuenta de pago aprobada y activa de la relación a la fecha (COL-8): la vigencia la da el tramo
 * valid_from / valid_to; una cuenta pendiente de aprobación o rechazada no se usa.
 */
final class PaymentAccountsAdapter implements PaymentAccounts
{
    public function accountAt(string $employmentId, string $date, string $purpose = 'salary'): ?array
    {
        $a = BankAccount::query()->where('employment_id', $employmentId)->where('purpose', $purpose)
            ->where('status', 'active')->where('is_active', true)->where('valid_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderByDesc('valid_from')->first();
        if (! $a instanceof BankAccount) {
            return null;
        }

        return [
            'id' => $a->id, 'bank_code' => $a->bank_code, 'account_type' => $a->account_type,
            'account_number' => FieldCipher::decrypt($a->account_number_enc),
            'interbank_code' => $a->interbank_code_enc === null ? null : FieldCipher::decrypt($a->interbank_code_enc),
            'currency' => $a->currency,
        ];
    }
}
