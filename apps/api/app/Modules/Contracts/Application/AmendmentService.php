<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\AmendmentStateMachine;
use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Labor\AssignmentChanges;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CON-3 · Adendas: modifican condiciones sin cambiar de contrato. Se generan, se firman y, firmadas, aplican
 * sus cambios con vigencia desde su fecha (RN-CON-08):
 *
 * - salary: nueva fila de compensación (employment_compensations) enlazada a la adenda.
 * - position: movimiento de la asignación por el contrato Shared\Labor\AssignmentChanges (Core HR).
 * - schedule / modality: jornada semanal y modalidad del contrato.
 * - extension: amplía la fecha de fin del contrato validando los topes (RN-CON-02 y RN-CON-03).
 * - other: queda registrada con su documento, sin efectos automáticos.
 */
final class AmendmentService
{
    public function __construct(
        private readonly AmendmentStateMachine $machine,
        private readonly ContractStateMachine $contracts,
        private readonly ContractTerms $terms,
        private readonly ContractDocuments $documents,
        private readonly AssignmentChanges $assignments,
        private readonly EffectiveDated $validity,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Contract $contract, array $input, bool $canSalary, ?string $userId): ContractAddendum
    {
        $this->contracts->getNextState($contract->status, 'amend');
        $kind = (string) $input['kind'];
        $effective = EffectiveDated::day((string) $input['effective_on']);
        if ($effective < $contract->valid_from->toDateString() || ($contract->valid_to !== null && $effective > $contract->valid_to->toDateString())) {
            throw ValidationException::withMessages(['effective_on' => [(string) trans('contracts.addenda.effective_out_of_range')]]);
        }
        $changes = $this->changes($kind, is_array($input['changes'] ?? null) ? $input['changes'] : [], $contract, $canSalary);
        $number = $contract->number.'-A'.(ContractAddendum::query()->where('contract_id', $contract->id)->count() + 1);

        return DB::transaction(function () use ($contract, $kind, $effective, $changes, $input, $number): ContractAddendum {
            $a = ContractAddendum::query()->create([
                'contract_id' => $contract->id, 'number' => $number, 'kind' => $kind, 'effective_on' => $effective,
                'description' => trim((string) $input['description']), 'changes' => $changes, 'status' => 'draft',
            ]);
            // El sueldo de la adenda no va a la auditoría (dato sensible): solo que se pactó un cambio.
            $this->audit->record('contract_addendum.created', 'contract_addendum', $a->id, null, ['contract_id' => $contract->id, 'kind' => $kind, 'effective_on' => $effective]);

            return $a;
        });
    }

    /** Borrador → En firma: genera el documento de la adenda. */
    public function issue(ContractAddendum $a, Contract $contract, ?string $userId): void
    {
        $next = $this->machine->getNextState($a->status, 'issue');
        DB::transaction(function () use ($a, $contract, $next, $userId): void {
            $a->status = $next;
            $a->document_id = $this->documents->generateAddendum($a, $contract, $userId);
            $a->save();
            $this->audit->record('contract_addendum.issued', 'contract_addendum', $a->id, ['status' => 'draft'], ['status' => $next, 'document_id' => $a->document_id]);
        });
    }

    public function cancel(ContractAddendum $a, string $reason): void
    {
        $next = $this->machine->getNextState($a->status, 'cancel');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('contracts.contracts.reason_required')]]);
        }
        DB::transaction(function () use ($a, $next, $reason): void {
            $before = $a->status;
            $a->status = $next;
            $a->cancellation_reason = $reason;
            $a->save();
            $this->audit->record('contract_addendum.cancelled', 'contract_addendum', $a->id, ['status' => $before], ['status' => $next], $reason);
        });
    }

    /** Registra la adenda firmada y aplica sus cambios con vigencia (RN-CON-08). */
    public function registerSignature(ContractAddendum $a, Contract $contract, string $contents, string $name, string $mime, string $origin, string $signedOn, ?string $userId): void
    {
        $next = $this->machine->getNextState($a->status, 'register-signature');
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['file' => [(string) trans('contracts.contracts.file_type')]]);
        }
        if ($signedOn > now()->toDateString()) {
            throw ValidationException::withMessages(['signed_on' => [(string) trans('contracts.contracts.signed_future')]]);
        }
        if ($a->status === 'draft' && $origin !== 'physical_scan') {
            throw ValidationException::withMessages(['origin' => [(string) trans('contracts.contracts.draft_needs_scan')]]);
        }
        if (! in_array($contract->status, [ContractStateMachine::ACTIVE, ContractStateMachine::SIGNED], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('contracts.addenda.contract_not_running'));
        }

        DB::transaction(function () use ($a, $contract, $next, $contents, $name, $mime, $origin, $signedOn, $userId): void {
            $title = (string) trans('contracts.addenda.document_title', ['number' => $a->number]);
            $a->document_id = $this->documents->attachSigned($a->document_id, $contract->employment_id, 'contract_addendum', $a->id, $title, $contents, $name, $mime, $userId);
            $a->applied_refs = $this->apply($a, $contract, $userId);
            $before = $a->status;
            $a->status = $next;
            $a->signed_at = Carbon::parse($signedOn);
            $a->signature_origin = $origin;
            $a->save();
            $this->outbox->record(DomainEventTypes::CONTRACT_AMENDED, 'contract', $contract->id, [
                'contract_id' => $contract->id, 'addendum_id' => $a->id, 'kind' => $a->kind, 'effective_on' => $a->effective_on->toDateString(),
            ]);
            $this->audit->record('contract_addendum.signed', 'contract_addendum', $a->id, ['status' => $before], ['status' => $next, 'applied_refs' => $a->applied_refs, 'signed_on' => $signedOn]);
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function changes(string $kind, array $changes, Contract $contract, bool $canSalary): array
    {
        $fail = fn (string $field, string $key) => throw ValidationException::withMessages([$field => [(string) trans('contracts.addenda.'.$key)]]);
        switch ($kind) {
            case 'salary':
                if (! $canSalary) {
                    throw new AyniException('forbidden', 403, (string) trans('contracts.addenda.salary_forbidden'));
                }
                $salary = $changes['base_salary'] ?? null;
                $amount = is_array($salary) ? (string) ($salary['amount'] ?? '') : '';
                $currency = is_array($salary) ? strtoupper((string) ($salary['currency'] ?? '')) : '';
                if (! is_numeric($amount) || preg_match('/^\d{1,12}(\.\d{1,2})?$/', $amount) !== 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || preg_match('/[1-9]/', $amount) !== 1) {
                    $fail('changes.base_salary', 'salary_invalid');
                }

                return ['base_salary' => ['amount' => $amount, 'currency' => $currency]];
            case 'position':
                $picked = array_intersect_key($changes, array_flip(AssignmentChanges::FIELDS));
                if ($picked === []) {
                    $fail('changes', 'position_empty');
                }

                return $picked;
            case 'schedule':
                $hours = $changes['weekly_hours'] ?? null;
                if (! is_numeric($hours) || (float) $hours <= 0 || (float) $hours > 168) {
                    $fail('changes.weekly_hours', 'hours_invalid');
                }

                return ['weekly_hours' => (string) $hours];
            case 'modality':
                if (! in_array($changes['work_modality'] ?? null, ['onsite', 'remote', 'hybrid'], true)) {
                    $fail('changes.work_modality', 'modality_invalid');
                }

                return ['work_modality' => (string) $changes['work_modality']];
            case 'extension':
                $end = isset($changes['ends_on']) && is_string($changes['ends_on']) ? EffectiveDated::day($changes['ends_on']) : null;
                if ($end === null || ($contract->valid_to !== null && $end <= $contract->valid_to->toDateString())) {
                    $fail('changes.ends_on', 'extension_invalid');
                }
                $this->terms->accumulation($contract->employment_id, $contract->contract_type_id, $contract->valid_from->toDateString(), (string) $end, $contract->id, $this->country(), true);

                return ['ends_on' => (string) $end];
            default:
                return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function apply(ContractAddendum $a, Contract $contract, ?string $userId): array
    {
        $changes = $a->changes ?? [];
        $from = $a->effective_on->toDateString();
        $reason = 'addendum:'.$a->number;
        $tenant = TenancyScope::requireTenant();

        return match ($a->kind) {
            'salary' => ['employment_compensation_id' => $this->validity->change('employment_compensations',
                ['tenant_id' => $tenant, 'employment_id' => $contract->employment_id], [
                    'base_salary' => (string) $changes['base_salary']['amount'], 'currency' => (string) $changes['base_salary']['currency'],
                    'pay_frequency' => (string) (DB::table('employment_compensations')->where('employment_id', $contract->employment_id)
                        ->where('valid_from', '<=', $from)->orderByDesc('valid_from')->value('pay_frequency') ?? 'monthly'),
                    'change_reason' => 'adjustment',
                ], $from)],
            'position' => ['personnel_action_id' => $this->assignments->apply($contract->employment_id, $changes, $from, $reason, $userId)],
            'schedule' => $this->updateContract($contract, ['weekly_hours' => $changes['weekly_hours']]),
            'modality' => $this->updateContract($contract, ['work_modality' => $changes['work_modality']]),
            'extension' => $this->updateContract($contract, ['valid_to' => $changes['ends_on']]),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function updateContract(Contract $contract, array $values): array
    {
        $before = array_intersect_key($contract->getAttributes(), $values);
        $contract->fill($values)->save();
        $this->audit->record('contract.amended', 'contract', $contract->id, $before, $values);

        return ['contract_id' => $contract->id, 'fields' => array_keys($values)];
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
    }
}
