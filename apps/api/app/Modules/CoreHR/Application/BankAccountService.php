<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\BankAccount;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Security\FieldCipher;
use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-8 · Cuentas bancarias y datos de pago.
 *
 * - Una cuenta por relación laboral y finalidad (haberes, CTS) vigente a la vez, con historia: la nueva
 *   cierra la anterior el día previo a su inicio (la base impide dos activas que se solapen).
 * - RN-COL-11: el número es campo sensible y se guarda cifrado; un cambio exige el permiso de campo,
 *   queda pendiente hasta que otra persona lo aprueba (colaboradores.cuenta_bancaria_requiere_aprobacion)
 *   y se avisa al titular por un canal distinto (outbox → aviso en la aplicación y mensaje al celular).
 * - Nada se borra: una solicitud se rechaza o se cancela con motivo y queda en la historia.
 */
final class BankAccountService
{
    public const PURPOSES = ['salary', 'cts', 'other'];

    public const ACCOUNT_TYPES = ['savings', 'checking', 'wallet'];

    public function __construct(
        private readonly PaymentFormats $formats,
        private readonly ParameterResolver $parameters,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<BankAccount>
     */
    public function forEmployment(Employment $employment): array
    {
        return array_values(BankAccount::query()->where('employment_id', $employment->id)
            ->orderByRaw("CASE status WHEN 'pending_approval' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->orderBy('purpose')->orderByDesc('valid_from')->get()->all());
    }

    /**
     * Registra una cuenta nueva o el cambio de la vigente. Devuelve la cuenta y los avisos de formato.
     *
     * @param  array<string, mixed>  $input
     * @return array{account: BankAccount, warnings: list<string>}
     */
    public function request(Employment $employment, array $input, string $userId): array
    {
        $country = (string) (DB::table('legal_entities')->where('id', $employment->legal_entity_id)->value('country_code') ?? 'PE');
        $bank = (string) $input['bank_code'];
        if (! $this->bankExists($bank, $country)) {
            throw ValidationException::withMessages(['bank_code' => [(string) trans('people.payments.bank_unknown')]]);
        }
        $number = $this->formats->accountNumber((string) $input['account_number'], $country);
        $warnings = [];
        $interbank = null;
        if (isset($input['interbank_code']) && is_string($input['interbank_code']) && trim($input['interbank_code']) !== '') {
            $checked = $this->formats->interbankCode($input['interbank_code'], $country);
            $interbank = $checked['value'];
            $warnings = $checked['warnings'];
        }
        $from = isset($input['valid_from']) && is_string($input['valid_from']) ? $input['valid_from'] : now()->toDateString();
        if ($from < $employment->hire_date->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('people.before_hire')]]);
        }
        $purpose = (string) $input['purpose'];
        $needsApproval = $this->parameters->value('colaboradores.cuenta_bancaria_requiere_aprobacion', $employment->legal_entity_id) !== false;

        $account = DB::transaction(function () use ($employment, $input, $bank, $number, $interbank, $from, $purpose, $needsApproval, $userId): BankAccount {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['bank-account:'.$employment->id.':'.$purpose]);
            if (BankAccount::query()->where('employment_id', $employment->id)->where('purpose', $purpose)->where('status', 'pending_approval')->exists()) {
                throw new AyniException('conflict', 409, (string) trans('people.payments.pending_exists'));
            }
            $account = BankAccount::create([
                'person_id' => $employment->person_id, 'employment_id' => $employment->id, 'purpose' => $purpose, 'bank_code' => $bank,
                'account_type' => (string) $input['account_type'], 'account_number_enc' => FieldCipher::encrypt($number),
                'interbank_code_enc' => $interbank === null ? null : FieldCipher::encrypt($interbank),
                'currency' => strtoupper((string) $input['currency']), 'is_active' => false, 'status' => 'pending_approval',
                'requested_by_user_id' => $userId, 'valid_from' => $from,
            ]);
            $this->audit->record('bank_account.requested', 'bank_account', $account->id, null, $this->auditable($account));
            $this->outbox->record(DomainEventTypes::BANK_ACCOUNT_CHANGE_REQUESTED, 'bank_account', $account->id, $this->eventPayload($account));
            if (! $needsApproval) {
                $account = $this->activate($account, $userId, null);
            }

            return $account->refresh();
        });

        return ['account' => $account, 'warnings' => $warnings];
    }

    /**
     * Aprobación de cuatro ojos: quien la aprueba no es quien la registró (RN-COL-11).
     */
    public function approve(BankAccount $account, string $userId, ?string $notes): BankAccount
    {
        $this->assertPending($account, ['approve', 'reject', 'cancel']);
        if ($account->requested_by_user_id === $userId) {
            throw new AyniException('four_eyes', 403, (string) trans('people.payments.same_approver'));
        }

        return DB::transaction(fn (): BankAccount => $this->activate($account, $userId, $notes)->refresh());
    }

    public function reject(BankAccount $account, string $userId, string $reason): BankAccount
    {
        $this->assertPending($account, ['approve', 'reject', 'cancel']);

        return $this->decide($account, 'rejected', $userId, $reason, 'bank_account.rejected');
    }

    public function cancel(BankAccount $account, string $userId, string $reason): BankAccount
    {
        $this->assertPending($account, ['approve', 'reject', 'cancel']);

        return $this->decide($account, 'cancelled', $userId, $reason, 'bank_account.cancelled');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(BankAccount $a): array
    {
        $number = FieldCipher::decrypt($a->account_number_enc);
        $interbank = $a->interbank_code_enc === null ? null : FieldCipher::decrypt($a->interbank_code_enc);
        $row = [
            'id' => $a->id,
            'employment_id' => $a->employment_id,
            'purpose' => $a->purpose,
            'bank_code' => $a->bank_code,
            'bank_name' => $this->bankName($a->bank_code),
            'account_type' => $a->account_type,
            'currency' => $a->currency,
            'account_number' => $number,
            'account_number_masked' => PaymentFormats::mask($number),
            'interbank_code' => $interbank,
            'interbank_code_masked' => $interbank === null ? null : PaymentFormats::mask($interbank),
            'status' => $a->status,
            'valid_from' => $a->valid_from->toDateString(),
            'valid_to' => $a->valid_to?->toDateString(),
            'is_current' => $a->status === 'active' && $a->valid_from->toDateString() <= now()->toDateString()
                && ($a->valid_to === null || $a->valid_to->toDateString() >= now()->toDateString()),
            'requested_by_user_id' => $a->requested_by_user_id,
            'requested_by_name' => $a->requested_by_user_id === null ? null : DB::table('users')->where('id', $a->requested_by_user_id)->value('display_name'),
            'decided_by_name' => $a->decided_by_user_id === null ? null : DB::table('users')->where('id', $a->decided_by_user_id)->value('display_name'),
            'decided_at' => $a->decided_at?->toIso8601String(),
            'decision_reason' => $a->decision_reason,
            'version' => $a->lock_version,
            'created_at' => $a->created_at->toIso8601String(),
        ];
        if ($interbank === null) {
            unset($row['interbank_code']);
        }

        return $row;
    }

    private function activate(BankAccount $account, string $userId, ?string $notes): BankAccount
    {
        $from = $account->valid_from->toDateString();
        $today = now()->toDateString();
        if ($from < $today) {
            // Aprobada después de la fecha pedida: rige desde hoy (los pagos ya hechos no cambian de cuenta).
            $from = $today;
        }
        $previous = BankAccount::query()->where('employment_id', $account->employment_id)->where('purpose', $account->purpose)
            ->where('status', 'active')->whereKeyNot($account->id)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $from))->lockForUpdate()->get();
        foreach ($previous as $old) {
            if ($old->valid_from->toDateString() >= $from) {
                // Una cuenta activa que empezaba el mismo día o después queda reemplazada por la nueva.
                $old->forceFill(['status' => 'cancelled', 'is_active' => false, 'decision_reason' => (string) trans('people.payments.replaced')])->save();

                continue;
            }
            $old->forceFill(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString(), 'is_active' => false])->save();
        }
        $before = $this->auditable($account);
        $account->forceFill([
            'status' => 'active', 'is_active' => true, 'valid_from' => $from, 'decided_by_user_id' => $userId,
            'decided_at' => now(), 'decision_reason' => $notes, 'verified_at' => now(),
        ]);
        $account->lock_version++;
        $account->save();
        $this->audit->record('bank_account.approved', 'bank_account', $account->id, $before, $this->auditable($account), $notes);
        $this->outbox->record(DomainEventTypes::BANK_ACCOUNT_CHANGED, 'bank_account', $account->id, $this->eventPayload($account) + ['effective_from' => $from]);

        return $account;
    }

    private function decide(BankAccount $account, string $status, string $userId, string $reason, string $action): BankAccount
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('people.reason_required')]]);
        }

        return DB::transaction(function () use ($account, $status, $userId, $reason, $action): BankAccount {
            $before = $this->auditable($account);
            $account->forceFill(['status' => $status, 'decided_by_user_id' => $userId, 'decided_at' => now(), 'decision_reason' => trim($reason)]);
            $account->lock_version++;
            $account->save();
            $this->audit->record($action, 'bank_account', $account->id, $before, $this->auditable($account), trim($reason));

            return $account->refresh();
        });
    }

    /**
     * @param  list<string>  $allowed  acciones de una solicitud pendiente
     */
    private function assertPending(BankAccount $account, array $allowed): void
    {
        unset($allowed);
        if ($account->status !== 'pending_approval') {
            throw new InvalidTransitionException($account->status, []);
        }
    }

    private function bankExists(string $code, string $country): bool
    {
        $catalog = 'banks_'.strtolower($country);
        $master = DB::table('master_catalog_items as i')->join('master_catalogs as c', 'c.id', '=', 'i.master_catalog_id')
            ->where('c.code', $catalog)->where('i.code', $code)->exists();

        return $master || DB::table('catalog_items as i')->join('catalogs as c', 'c.id', '=', 'i.catalog_id')
            ->where('c.code', $catalog)->where('i.code', $code)->where('i.is_active', true)->whereNull('i.deleted_at')->exists();
    }

    private function bankName(string $code): string
    {
        $name = DB::table('master_catalog_items as i')->join('master_catalogs as c', 'c.id', '=', 'i.master_catalog_id')
            ->where('c.code', 'like', 'banks_%')->where('i.code', $code)->value('i.label')
            ?? DB::table('catalog_items as i')->join('catalogs as c', 'c.id', '=', 'i.catalog_id')
                ->where('c.code', 'like', 'banks_%')->where('i.code', $code)->value('i.label');

        return is_string($name) ? $name : $code;
    }

    /**
     * Sin el número: los consumidores avisan al titular y planilla lee la cuenta por su id.
     *
     * @return array<string, mixed>
     */
    private function eventPayload(BankAccount $a): array
    {
        return [
            'bank_account_id' => $a->id, 'employment_id' => $a->employment_id, 'person_id' => $a->person_id, 'purpose' => $a->purpose,
            'bank_code' => $a->bank_code, 'account_number_last4' => substr(FieldCipher::decrypt($a->account_number_enc), -4),
            'status' => $a->status,
        ];
    }

    /**
     * El número y el CCI se cifran en la auditoría por el mapa de campos de BankAccount.
     *
     * @return array<string, mixed>
     */
    private function auditable(BankAccount $a): array
    {
        return [
            'purpose' => $a->purpose, 'bank_code' => $a->bank_code, 'account_type' => $a->account_type, 'currency' => $a->currency,
            'account_number' => FieldCipher::decrypt($a->account_number_enc),
            'interbank_code' => $a->interbank_code_enc === null ? null : FieldCipher::decrypt($a->interbank_code_enc),
            'status' => $a->status, 'valid_from' => $a->valid_from->toDateString(), 'valid_to' => $a->valid_to?->toDateString(),
        ];
    }
}
