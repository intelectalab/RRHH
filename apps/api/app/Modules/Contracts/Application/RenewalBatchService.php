<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Modules\Contracts\Domain\RenewalBatchStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractRenewalBatch;
use App\Modules\Contracts\Infrastructure\Models\ContractRenewalBatchItem;
use App\Shared\Audit\AuditLogger;
use App\Shared\Signatures\SignatureRequests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CON-5 · Renovación masiva con firma (E-08): se eligen contratos por vencer y la nueva fecha de fin; cada contrato
 * se revisa contra los topes acumulados (RN-CON-02/03) y el que los supera se excluye y se informa. Al enviar, cada
 * renovación sigue el mismo camino que la individual (CON-3: contrato sucesor, documento desde plantilla) y su
 * documento va a firma con el flujo común (DOC-6). El lote muestra el avance de firma por contrato.
 */
final class RenewalBatchService
{
    public function __construct(
        private readonly RenewalBatchStateMachine $machine,
        private readonly ContractService $contracts,
        private readonly ContractTerms $terms,
        private readonly SignatureRequests $signatures,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name?: string|null, legal_entity_id?: string|null, new_end_date: string, contract_ids: list<string>}  $input
     */
    public function create(array $input, string $userId): ContractRenewalBatch
    {
        $end = CarbonImmutable::parse($input['new_end_date'])->toDateString();
        $contracts = Contract::query()->whereIn('id', $input['contract_ids'])->get()->keyBy('id');
        if ($contracts->count() !== count(array_unique($input['contract_ids']))) {
            throw ValidationException::withMessages(['contract_ids' => [(string) trans('contracts.renewal_batches.unknown_contract')]]);
        }
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');

        $name = trim((string) ($input['name'] ?? ''));

        return DB::transaction(function () use ($input, $name, $end, $contracts, $country, $userId): ContractRenewalBatch {
            $batch = ContractRenewalBatch::query()->create([
                'name' => $name !== '' ? $name : (string) trans('contracts.renewal_batches.default_name', ['date' => $end]),
                'legal_entity_id' => $input['legal_entity_id'] ?? null, 'new_end_date' => $end, 'status' => 'draft', 'created_by_user_id' => $userId,
            ]);
            foreach ($contracts as $c) {
                /** @var Contract $c */
                [$decision, $exceeds, $days, $notes] = $this->review($c, $end, $country);
                ContractRenewalBatchItem::query()->create([
                    'contract_renewal_batch_id' => $batch->id, 'contract_id' => $c->id, 'decision' => $decision, 'new_valid_to' => $end,
                    'accumulated_days_after' => $days, 'exceeds_limit' => $exceeds, 'notes' => $notes,
                ]);
            }
            $this->audit->record('contract_renewal_batch.created', 'contract_renewal_batch', $batch->id, null, [
                'contracts' => $contracts->count(), 'new_end_date' => $end,
            ]);

            return $batch;
        });
    }

    /** Borrador → Enviado a firma: genera cada renovación y la envía a firma; lo que falla queda informado en su fila. */
    public function send(ContractRenewalBatch $batch, string $userId): ContractRenewalBatch
    {
        $next = $this->machine->getNextState($batch->status, 'send');
        foreach ($batch->items()->where('decision', 'renew')->get() as $item) {
            try {
                /** @var Contract $contract */
                $contract = Contract::query()->findOrFail($item->contract_id);
                [$successor] = $this->contracts->renew($contract, ['ends_on' => $item->new_valid_to?->toDateString() ?? $batch->new_end_date?->toDateString()], $userId);
                $this->contracts->submit($successor, $userId);
                $successor->refresh();
                if ($successor->status === ContractStateMachine::PENDING_APPROVAL) {
                    $this->contracts->approve($successor, $userId);
                    $successor->refresh();
                }
                $requestId = $this->signatures->sendForEmployeeAndRepresentative((string) $successor->document_id,
                    (string) trans('contracts.contracts.document_title', ['number' => (string) $successor->number]), $userId);
                $successor->signature_request_id = $requestId;
                $successor->save();
                $item->new_contract_id = $successor->id;
                $item->save();
            } catch (Throwable $e) {
                $item->decision = 'pending';
                $item->notes = mb_substr($e->getMessage(), 0, 500);
                $item->save();
            }
        }
        $before = $batch->status;
        $batch->status = $next;
        $batch->sent_at = now();
        $batch->save();
        $this->audit->record('contract_renewal_batch.sent', 'contract_renewal_batch', $batch->id, ['status' => $before], ['status' => $next]);

        return $this->refreshCompletion($batch);
    }

    public function cancel(ContractRenewalBatch $batch): ContractRenewalBatch
    {
        $before = $batch->status;
        $batch->status = $this->machine->getNextState($batch->status, 'cancel');
        $batch->save();
        $this->audit->record('contract_renewal_batch.cancelled', 'contract_renewal_batch', $batch->id, ['status' => $before], ['status' => $batch->status]);

        return $batch;
    }

    /** El lote se completa cuando todas sus renovaciones generadas están firmadas. */
    public function refreshCompletion(ContractRenewalBatch $batch): ContractRenewalBatch
    {
        if ($batch->status !== 'sent_to_signature') {
            return $batch;
        }
        $ids = $batch->items()->whereNotNull('new_contract_id')->pluck('new_contract_id')->all();
        $pending = $batch->items()->where('decision', 'renew')->whereNull('new_contract_id')->exists();
        if ($ids !== [] && ! $pending && Contract::query()->whereIn('id', $ids)->whereNotIn('status', [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE])->doesntExist()) {
            $batch->status = $this->machine->getNextState($batch->status, 'complete');
            $batch->completed_at = now();
            $batch->save();
            $this->audit->record('contract_renewal_batch.completed', 'contract_renewal_batch', $batch->id, null, ['status' => $batch->status]);
        }

        return $batch;
    }

    /**
     * Avance de firma por contrato del lote.
     *
     * @return array<string, array{status: string, signed: int, total: int}>
     */
    public function progress(ContractRenewalBatch $batch): array
    {
        $map = Contract::query()->whereIn('id', $batch->items()->whereNotNull('new_contract_id')->pluck('new_contract_id')->all())
            ->whereNotNull('signature_request_id')->pluck('signature_request_id', 'id')->all();
        $progress = $this->signatures->progress(array_values(array_map('strval', $map)));
        $out = [];
        foreach ($map as $contractId => $requestId) {
            if (isset($progress[(string) $requestId])) {
                $out[(string) $contractId] = $progress[(string) $requestId];
            }
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: bool, 2: int|null, 3: string|null}
     */
    private function review(Contract $c, string $end, string $country): array
    {
        if (! in_array($c->status, [ContractStateMachine::ACTIVE, ContractStateMachine::EXPIRED], true) || $c->valid_to === null) {
            return ['not_renew', false, null, (string) trans('contracts.renewal_batches.not_renewable')];
        }
        if (Contract::query()->where('renewal_of_contract_id', $c->id)->whereNotIn('status', ['cancelled'])->exists()) {
            return ['not_renew', false, null, (string) trans('contracts.contracts.renewal_exists')];
        }
        $start = CarbonImmutable::parse($c->valid_to->toDateString())->addDay()->toDateString();
        if ($end < $start) {
            return ['not_renew', false, null, (string) trans('contracts.renewal_batches.end_before_start')];
        }
        $acc = $this->terms->accumulation($c->employment_id, $c->contract_type_id, $start, $end, null, $country, false);
        $exceeds = ($acc['modality_max_days'] !== null && $acc['modality_days'] > $acc['modality_max_days'])
            || ($acc['total_max_days'] !== null && $acc['total_days'] > $acc['total_max_days']);

        return $exceeds
            ? ['not_renew', true, (int) $acc['modality_days'], (string) trans('contracts.renewal_batches.exceeds', ['explanation' => (string) $acc['explanation']])]
            : ['renew', false, (int) $acc['modality_days'], null];
    }
}
