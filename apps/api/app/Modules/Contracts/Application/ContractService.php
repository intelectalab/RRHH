<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Vigencia\EffectiveDated;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CON-2 · CON-3 · Ciclo de vida del contrato (ME-11), su documento y sus renovaciones.
 *
 * - El ingreso (COL-2) deja el contrato inicial en Borrador; aquí se completa, se envía a aprobación (si
 *   `contratos.requiere_aprobacion`), se genera el documento y se registra la firma subiendo el ejemplar
 *   firmado (RN-CON-06). Un contrato físico ya firmado se registra directo desde Borrador.
 * - Nada se sobrescribe: el contrato tiene vigencia (valid_from / valid_to) y renovar crea un contrato
 *   sucesor enlazado; el anterior termina cuando el sucesor empieza a regir.
 * - La firma electrónica (M-DOC) se conectará al puerto de firma cuando exista; hoy el origen es
 *   `physical_scan` (escaneo del ejemplar firmado).
 */
final class ContractService
{
    /** Estados en los que hay un contrato en curso (no se crea otro: se renueva). */
    private const OPEN = ['draft', 'pending_approval', 'pending_signature', 'signed', 'active'];

    public function __construct(
        private readonly ContractStateMachine $machine,
        private readonly ContractTerms $terms,
        private readonly ContractDocuments $documents,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * Contrato nuevo en Borrador para una relación sin contrato en curso (p. ej. relaciones cargadas antes
     * de CON-2). Para continuar un contrato a plazo se usa renovar.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?string $userId): Contract
    {
        $employment = $this->openEmployment((string) $input['employment_id']);
        if (Contract::query()->where('employment_id', $employment->id)->whereIn('status', self::OPEN)->exists()) {
            throw new AyniException('contract_exists', 409, (string) trans('contracts.contracts.already_open'));
        }
        $values = $this->values($input, null) + ['employment_id' => $employment->id, 'status' => ContractStateMachine::DRAFT];
        $values['valid_from'] ??= (string) $employment->hire_date;
        $this->terms->validate($this->termsOf($values), $this->country());

        return DB::transaction(function () use ($values): Contract {
            $contract = Contract::query()->create($values);
            $this->audit->record('contract.created', 'contract', $contract->id, null, $this->snapshot($contract->refresh()));

            return $contract;
        });
    }

    /**
     * Completa o corrige el Borrador (tipo, fechas, causa, periodo de prueba, jornada, modalidad, número).
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Contract $contract, array $input, ?int $ifMatch): Contract
    {
        if ($ifMatch !== null && $ifMatch !== $contract->lock_version) {
            throw new AyniException('conflict', 409, (string) trans('contracts.contracts.version_conflict'));
        }
        if ($contract->status !== ContractStateMachine::DRAFT) {
            throw new InvalidTransitionException($contract->status, $this->machine->manualActions($contract->status), (string) trans('contracts.contracts.only_draft'));
        }
        $values = $this->values($input, $contract);
        $before = $this->snapshot($contract);
        $contract->fill($values);
        $this->terms->validate($this->termsOf($contract->getAttributes()), $this->country());

        return DB::transaction(function () use ($contract, $before): Contract {
            $contract->save();
            $this->audit->record('contract.updated', 'contract', $contract->id, $before, $this->snapshot($contract->refresh()));

            return $contract;
        });
    }

    /**
     * Borrador → En aprobación (ME-11 «enviar_aprobacion»). Si la organización no exige aprobación, el
     * contrato queda directamente En firma con su documento generado.
     *
     * @return list<array{code: string, message: string}> advertencias (parámetros por validar)
     */
    public function submit(Contract $contract, ?string $userId): array
    {
        $this->machine->getNextState($contract->status, 'submit');
        $warnings = $this->check($contract);

        DB::transaction(function () use ($contract, $userId): void {
            $before = $contract->status;
            $contract->number ??= $this->nextNumber($contract);
            $contract->status = ContractStateMachine::PENDING_APPROVAL;
            $contract->save();
            $this->outbox->record(DomainEventTypes::CONTRACT_CREATED, 'contract', $contract->id, ['contract_id' => $contract->id, 'employment_id' => $contract->employment_id]);
            $this->audit->record('contract.submitted', 'contract', $contract->id, ['status' => $before], ['status' => $contract->status, 'number' => $contract->number]);
            $legalEntity = (string) DB::table('employments')->where('id', $contract->employment_id)->value('legal_entity_id');
            if ($this->parameters->value('contratos.requiere_aprobacion', $legalEntity) === false) {
                $this->issue($contract, $userId, 'contract.issued_without_approval');
            }
        });

        return $warnings;
    }

    /** En aprobación → En firma: se aprueba, se genera el documento y queda listo para firmar. */
    public function approve(Contract $contract, ?string $userId): void
    {
        $this->machine->getNextState($contract->status, 'approve');
        DB::transaction(fn () => $this->issue($contract, $userId, 'contract.approved'));
    }

    /** En aprobación → Borrador con observaciones. */
    public function returnToDraft(Contract $contract, string $reason, ?string $userId): void
    {
        $this->transition($contract, 'return', 'contract.returned', $reason);
    }

    /** En firma → Borrador: la firma fue rechazada o no se completó. */
    public function declineSignature(Contract $contract, string $reason, ?string $userId): void
    {
        $this->transition($contract, 'decline-signature', 'contract.signature_declined', $reason);
    }

    /** Anular antes de que empiece a regir (Borrador, En aprobación, En firma, Firmado). */
    public function void(Contract $contract, string $reason, ?string $userId): void
    {
        $this->transition($contract, 'void', 'contract.voided', $reason, function (Contract $c): void {
            $this->outbox->record(DomainEventTypes::CONTRACT_VOIDED, 'contract', $c->id, ['contract_id' => $c->id, 'employment_id' => $c->employment_id]);
        });
    }

    /**
     * Registra el ejemplar firmado por ambas partes (RN-CON-06). Desde En firma, o desde Borrador si es el
     * contrato físico escaneado (se valida como al enviarlo). Si la fecha de inicio ya llegó, rige.
     *
     * @return list<array{code: string, message: string}>
     */
    public function registerSignature(Contract $contract, string $contents, string $name, string $mime, string $origin, string $signedOn, ?string $userId): array
    {
        $this->machine->getNextState($contract->status, 'register-signature');
        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['file' => [(string) trans('contracts.contracts.file_type')]]);
        }
        if ($signedOn > now()->toDateString()) {
            throw ValidationException::withMessages(['signed_on' => [(string) trans('contracts.contracts.signed_future')]]);
        }
        $warnings = [];
        if ($contract->status === ContractStateMachine::DRAFT) {
            if ($origin !== 'physical_scan') {
                throw ValidationException::withMessages(['origin' => [(string) trans('contracts.contracts.draft_needs_scan')]]);
            }
            $warnings = $this->check($contract);
        }

        DB::transaction(function () use ($contract, $contents, $name, $mime, $origin, $signedOn, $userId): void {
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $before = $locked->status;
            $locked->number ??= $this->nextNumber($locked);
            $previous = $locked->renewal_of_contract_id === null ? null : Contract::query()->whereKey($locked->renewal_of_contract_id)->lockForUpdate()->first();
            if ($previous !== null && ($previous->valid_to === null || $previous->valid_to->toDateString() >= $locked->valid_from->toDateString())) {
                // Conversión o renovación anticipada: el anterior se acorta hasta el día previo (sin solape).
                $previous->valid_to = Carbon::parse($locked->valid_from->toDateString())->subDay();
                $previous->save();
            }
            $title = (string) trans('contracts.contracts.document_title', ['number' => $locked->number]);
            $locked->document_id = $this->documents->attachSigned($locked->document_id, $locked->employment_id, 'contract', $locked->id, $title, $contents, $name, $mime, $userId);
            $locked->status = ContractStateMachine::SIGNED;
            $locked->signed_at = Carbon::parse($signedOn);
            $locked->signature_origin = $origin;
            $locked->accumulated_days = $this->terms->accumulation($locked->employment_id, $locked->contract_type_id, $locked->valid_from->toDateString(),
                $locked->valid_to?->toDateString(), $locked->id, $this->country(), false)['modality_days'];
            $locked->save();
            $this->outbox->record(DomainEventTypes::CONTRACT_SIGNED, 'contract', $locked->id, ['contract_id' => $locked->id, 'employment_id' => $locked->employment_id, 'signature_origin' => $origin]);
            if ($previous !== null) {
                $this->outbox->record(DomainEventTypes::CONTRACT_RENEWED, 'contract', $locked->id, [
                    'contract_id' => $locked->id, 'renewal_of_contract_id' => $previous->id, 'employment_id' => $locked->employment_id,
                ]);
            }
            $this->audit->record('contract.signed', 'contract', $locked->id, ['status' => $before], ['status' => $locked->status, 'signature_origin' => $origin, 'signed_on' => $signedOn]);
            $this->applyDueFor($locked, now()->toDateString());
            $contract->setRawAttributes($locked->getAttributes(), true);
        });

        return $warnings;
    }

    /** Vigente o vencido → Terminado (término registrado; el cese efectivo lo cierra COL-11). */
    public function terminate(Contract $contract, string $reason, ?string $endOn, ?string $userId): void
    {
        $this->machine->getNextState($contract->status, 'terminate');
        $end = $endOn === null ? now()->toDateString() : EffectiveDated::day($endOn);
        if ($end < $contract->valid_from->toDateString() || ($contract->valid_to !== null && $end > $contract->valid_to->toDateString())) {
            throw ValidationException::withMessages(['effective_on' => [(string) trans('contracts.contracts.terminate_out_of_range')]]);
        }
        $this->transition($contract, 'terminate', 'contract.terminated', $reason, function (Contract $c) use ($end, $reason): void {
            $c->valid_to = Carbon::parse($end);
            $c->termination_reason = $reason;
            $this->outbox->record(DomainEventTypes::CONTRACT_TERMINATED, 'contract', $c->id, ['contract_id' => $c->id, 'employment_id' => $c->employment_id, 'reason' => $reason]);
        });
    }

    /**
     * CON-3 · Renovación (prórroga) o conversión: crea el contrato sucesor en Borrador, enlazado al anterior.
     * Valida RN-CON-01/07 y los topes acumulados (RN-CON-02 y RN-CON-03); superarlos devuelve 422
     * contract_limit_exceeded con la explicación y la sugerencia de convertir a plazo indeterminado.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: Contract, 1: list<array{code: string, message: string}>}
     */
    public function renew(Contract $contract, array $input, ?string $userId): array
    {
        $this->machine->getNextState($contract->status, 'renew');
        $this->openEmployment($contract->employment_id);
        if (Contract::query()->where('renewal_of_contract_id', $contract->id)->whereNotIn('status', ['cancelled'])->exists()) {
            throw new AyniException('renewal_exists', 409, (string) trans('contracts.contracts.renewal_exists'));
        }
        if ($contract->valid_to === null && ! isset($input['starts_on'])) {
            throw ValidationException::withMessages(['starts_on' => [(string) trans('contracts.contracts.indefinite_needs_start')]]);
        }
        $defaultStart = $contract->valid_to === null ? null : CarbonImmutable::parse($contract->valid_to->toDateString())->addDay()->toDateString();
        $start = isset($input['starts_on']) && is_string($input['starts_on']) ? EffectiveDated::day($input['starts_on']) : (string) $defaultStart;
        if ($start <= $contract->valid_from->toDateString() || ($defaultStart !== null && $start > $defaultStart)) {
            throw ValidationException::withMessages(['starts_on' => [(string) trans('contracts.contracts.renewal_start_range')]]);
        }
        $values = $this->values($input + ['contract_type_id' => $contract->contract_type_id], null);
        $values += [
            'objective_cause' => $contract->objective_cause, 'weekly_hours' => $contract->weekly_hours, 'work_modality' => $contract->work_modality,
        ];
        $values['valid_from'] = $start;
        $values['trial_period_ends_on'] = null;
        $warnings = $this->terms->validate($this->termsOf($values), $this->country());
        $this->terms->accumulation($contract->employment_id, (string) $values['contract_type_id'], $start, $values['valid_to'] ?? null, null, $this->country(), true);

        $successor = DB::transaction(function () use ($contract, $values): Contract {
            $successor = Contract::query()->create($values + [
                'employment_id' => $contract->employment_id, 'status' => ContractStateMachine::DRAFT, 'renewal_of_contract_id' => $contract->id,
            ]);
            $this->audit->record('contract.renewal_created', 'contract', $successor->id, null, $this->snapshot($successor->refresh()) + ['renewal_of_contract_id' => $contract->id]);

            return $successor;
        });

        return [$successor, $warnings];
    }

    /**
     * Tarea diaria (y al registrar la firma): firmados que empiezan → Vigente; renovados cuyo sucesor ya
     * rige → Terminado; contratos a plazo vencidos sin sucesor con la relación abierta → Vencido sin
     * regularizar (RN-CON-05); aviso del fin del periodo de prueba al jefe y a RRHH.
     *
     * @return array{started: int, completed: int, expired: int, probation_alerts: int}
     */
    public function applyDue(string $today): array
    {
        $counts = ['started' => 0, 'completed' => 0, 'expired' => 0, 'probation_alerts' => 0];
        foreach (Contract::query()->whereIn('status', [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE])->get() as $contract) {
            DB::transaction(function () use ($contract, $today, &$counts): void {
                foreach ($this->applyDueFor($contract, $today) as $k => $n) {
                    $counts[$k] += $n;
                }
            });
        }

        return $counts;
    }

    /**
     * @return array{started: int, completed: int, expired: int, probation_alerts: int}
     */
    private function applyDueFor(Contract $contract, string $today): array
    {
        $counts = ['started' => 0, 'completed' => 0, 'expired' => 0, 'probation_alerts' => 0];
        if ($contract->status === ContractStateMachine::SIGNED && $contract->valid_from->toDateString() <= $today) {
            $contract->status = $this->machine->getNextState($contract->status, 'start');
            $contract->save();
            $counts['started']++;
            $this->outbox->record(DomainEventTypes::CONTRACT_STARTED, 'contract', $contract->id, [
                'contract_id' => $contract->id, 'employment_id' => $contract->employment_id, 'starts_on' => $contract->valid_from->toDateString(),
            ]);
            $previous = $contract->renewal_of_contract_id === null ? null : Contract::query()->whereKey($contract->renewal_of_contract_id)->first();
            if ($previous !== null && in_array($previous->status, [ContractStateMachine::ACTIVE, ContractStateMachine::EXPIRED], true)) {
                $previous->status = $this->machine->getNextState($previous->status, 'complete');
                $previous->termination_reason = 'renewed';
                $previous->save();
                $counts['completed']++;
                $this->outbox->record(DomainEventTypes::CONTRACT_TERMINATED, 'contract', $previous->id, ['contract_id' => $previous->id, 'employment_id' => $previous->employment_id, 'reason' => 'renewed']);
            }
        }
        if ($contract->status === ContractStateMachine::ACTIVE && $contract->valid_to !== null && $contract->valid_to->toDateString() < $today) {
            $hasSuccessor = Contract::query()->where('renewal_of_contract_id', $contract->id)->whereIn('status', [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE])->exists();
            $open = in_array((string) DB::table('employments')->where('id', $contract->employment_id)->value('status'), ['pending', 'active', 'suspended'], true);
            if (! $hasSuccessor && $open) {
                $contract->status = $this->machine->getNextState($contract->status, 'expire');
                $contract->save();
                $counts['expired']++;
                $this->outbox->record(DomainEventTypes::CONTRACT_EXPIRED, 'contract', $contract->id, [
                    'contract_id' => $contract->id, 'employment_id' => $contract->employment_id, 'ends_on' => $contract->valid_to->toDateString(),
                ]);
            }
        }
        if ($contract->status === ContractStateMachine::ACTIVE && $contract->trial_period_ends_on !== null && $contract->probation_alerted_on === null) {
            $legalEntity = (string) DB::table('employments')->where('id', $contract->employment_id)->value('legal_entity_id');
            $days = max(0, (int) ($this->parameters->value('contratos.aviso_fin_prueba_dias', $legalEntity) ?? 0));
            $ends = $contract->trial_period_ends_on->toDateString();
            if ($ends >= $today && CarbonImmutable::parse($today)->addDays($days)->toDateString() >= $ends) {
                $contract->probation_alerted_on = Carbon::parse($today);
                $contract->save();
                $counts['probation_alerts']++;
                $this->outbox->record(DomainEventTypes::CONTRACT_PROBATION_ENDING, 'contract', $contract->id, [
                    'contract_id' => $contract->id, 'employment_id' => $contract->employment_id, 'trial_period_ends_on' => $ends,
                ]);
            }
        }

        return $counts;
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    private function check(Contract $contract): array
    {
        $warnings = $this->terms->validate($this->termsOf($contract->getAttributes()), $this->country());
        $this->terms->accumulation($contract->employment_id, $contract->contract_type_id, $contract->valid_from->toDateString(),
            $contract->valid_to?->toDateString(), $contract->id, $this->country(), true);

        return $warnings;
    }

    private function issue(Contract $contract, ?string $userId, string $auditAction): void
    {
        $before = $contract->status;
        $contract->status = $this->machine->getNextState($contract->status, 'approve');
        $contract->approved_by_user_id = $userId;
        $contract->approved_at = now();
        $contract->number ??= $this->nextNumber($contract);
        $contract->save();
        $contract->document_id = $this->documents->generateContract($contract, $userId);
        $contract->save();
        $this->outbox->record(DomainEventTypes::CONTRACT_ISSUED, 'contract', $contract->id, [
            'contract_id' => $contract->id, 'employment_id' => $contract->employment_id, 'document_id' => $contract->document_id,
        ]);
        $this->audit->record($auditAction, 'contract', $contract->id, ['status' => $before], ['status' => $contract->status, 'document_id' => $contract->document_id]);
    }

    private function transition(Contract $contract, string $action, string $auditAction, string $reason, ?\Closure $effects = null): void
    {
        $next = $this->machine->getNextState($contract->status, $action);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('contracts.contracts.reason_required')]]);
        }
        DB::transaction(function () use ($contract, $next, $auditAction, $reason, $effects): void {
            $before = $contract->status;
            $contract->status = $next;
            if ($effects !== null) {
                $effects($contract);
            }
            $contract->save();
            $this->audit->record($auditAction, 'contract', $contract->id, ['status' => $before], ['status' => $next], $reason);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function values(array $input, ?Contract $current): array
    {
        $map = ['contract_type_id' => 'contract_type_id', 'number' => 'number', 'starts_on' => 'valid_from', 'ends_on' => 'valid_to',
            'objective_cause' => 'objective_cause', 'trial_period_ends_on' => 'trial_period_ends_on', 'weekly_hours' => 'weekly_hours', 'work_modality' => 'work_modality'];
        $values = [];
        foreach ($map as $in => $col) {
            if (! array_key_exists($in, $input)) {
                continue;
            }
            $v = $input[$in];
            if (in_array($col, ['valid_from', 'valid_to', 'trial_period_ends_on'], true) && is_string($v) && $v !== '') {
                $v = EffectiveDated::day($v);
            }
            $values[$col] = is_string($v) && trim($v) === '' ? null : $v;
        }
        if ($current !== null && $current->renewal_of_contract_id !== null && array_key_exists('valid_from', $values)) {
            // La fecha de inicio de una renovación se fija al renovar.
            unset($values['valid_from']);
        }
        if (array_key_exists('contract_type_id', $values) && ! DB::table('contract_types')->where('id', $values['contract_type_id'])->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['contract_type_id' => [(string) trans('contracts.contracts.type_invalid')]]);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{contract_type_id: string, starts_on: string, ends_on: ?string, objective_cause: ?string, trial_period_ends_on: ?string, weekly_hours: ?string}
     */
    private function termsOf(array $a): array
    {
        $day = fn (mixed $v): ?string => $v === null || $v === '' ? null : EffectiveDated::day(is_string($v) ? $v : (string) $v);

        return [
            'contract_type_id' => (string) $a['contract_type_id'],
            'starts_on' => (string) $day($a['valid_from'] ?? null),
            'ends_on' => $day($a['valid_to'] ?? null),
            'objective_cause' => isset($a['objective_cause']) ? (string) $a['objective_cause'] : null,
            'trial_period_ends_on' => $day($a['trial_period_ends_on'] ?? null),
            'weekly_hours' => isset($a['weekly_hours']) && $a['weekly_hours'] !== '' ? (string) $a['weekly_hours'] : null,
        ];
    }

    private function openEmployment(string $employmentId): \stdClass
    {
        $employment = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at')->first(['id', 'status', 'hire_date']);
        if ($employment === null) {
            throw ValidationException::withMessages(['employment_id' => [(string) trans('contracts.contracts.employment_invalid')]]);
        }
        if (! in_array($employment->status, ['pending', 'active', 'suspended'], true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('contracts.contracts.employment_closed'));
        }

        return $employment;
    }

    /** Número correlativo por relación: código del colaborador y orden del contrato (C-0001-1, C-0001-2…). */
    private function nextNumber(Contract $contract): string
    {
        $code = (string) DB::table('employments')->where('id', $contract->employment_id)->value('employee_code');
        $n = Contract::query()->where('employment_id', $contract->employment_id)->whereNotNull('number')->count() + 1;

        return $code.'-'.$n;
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Contract $c): array
    {
        return [
            'contract_type_id' => $c->contract_type_id, 'number' => $c->number, 'status' => $c->status,
            'valid_from' => $c->valid_from->toDateString(), 'valid_to' => $c->valid_to?->toDateString(),
            'objective_cause' => $c->objective_cause, 'trial_period_ends_on' => $c->trial_period_ends_on?->toDateString(),
            'weekly_hours' => $c->weekly_hours, 'work_modality' => $c->work_modality,
        ];
    }
}
