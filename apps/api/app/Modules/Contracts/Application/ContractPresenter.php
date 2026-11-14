<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\AmendmentStateMachine;
use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Modules\Contracts\Infrastructure\Models\ContractAddendum;
use Illuminate\Support\Facades\DB;

/**
 * Forma de Contract y ContractAddendum en la API (kit/api/openapi.yaml). El sueldo pactado en una adenda
 * viaja aparte (salary_change) para que SensitiveFields lo quite sin permiso de campo (regla 6).
 */
final class ContractPresenter
{
    public function __construct(
        private readonly ContractStateMachine $machine,
        private readonly AmendmentStateMachine $amendments,
        private readonly ContractDocuments $documents,
        private readonly ContractTerms $terms,
    ) {}

    /**
     * @param  list<Contract>  $contracts
     * @return list<array<string, mixed>>
     */
    public function many(array $contracts): array
    {
        if ($contracts === []) {
            return [];
        }
        $employmentIds = array_values(array_unique(array_map(fn (Contract $c) => $c->employment_id, $contracts)));
        $employments = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->join('legal_entities as l', 'l.id', '=', 'e.legal_entity_id')->whereIn('e.id', $employmentIds)
            ->get(['e.id', 'e.employee_code', 'e.legal_entity_id', 'l.legal_name', 'l.trade_name', 'p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname'])
            ->keyBy('id');
        $types = DB::table('contract_types')->whereIn('id', array_values(array_unique(array_map(fn (Contract $c) => $c->contract_type_id, $contracts))))
            ->get(['id', 'name', 'category'])->keyBy('id');
        $successors = DB::table('contracts')->whereIn('renewal_of_contract_id', array_map(fn (Contract $c) => $c->id, $contracts))
            ->whereNull('deleted_at')->where('status', '<>', 'cancelled')->pluck('id', 'renewal_of_contract_id');

        return array_map(function (Contract $c) use ($employments, $types, $successors): array {
            $e = $employments->get($c->employment_id);
            $t = $types->get($c->contract_type_id);
            $name = $e === null ? null : trim(implode(' ', array_filter([
                $e->preferred_name ?: $e->first_names, $e->paternal_surname, $e->maternal_surname,
            ])));

            return [
                'id' => $c->id,
                'employment_id' => $c->employment_id,
                'person_name' => $name,
                'employee_code' => $e?->employee_code,
                'legal_entity_id' => $e?->legal_entity_id,
                'legal_entity_name' => $e === null ? null : ($e->trade_name ?: $e->legal_name),
                'contract_type_id' => $c->contract_type_id,
                'contract_type_name' => $t?->name,
                'contract_type_category' => $t?->category,
                'number' => $c->number,
                'starts_on' => $c->valid_from->toDateString(),
                'ends_on' => $c->valid_to?->toDateString(),
                'trial_period_ends_on' => $c->trial_period_ends_on?->toDateString(),
                'weekly_hours' => $c->weekly_hours === null ? null : (float) $c->weekly_hours,
                'work_modality' => $c->work_modality,
                'objective_cause' => $c->objective_cause,
                'status' => $c->status,
                'renewal_of_contract_id' => $c->renewal_of_contract_id,
                'renewed_by_contract_id' => $successors[$c->id] ?? null,
                'has_document' => $c->document_id !== null,
                'document_version' => $this->documents->version($c->document_id),
                'signature_origin' => $c->signature_origin,
                'signed_at' => $c->signed_at?->toIso8601String(),
                'approved_at' => $c->approved_at?->toIso8601String(),
                'accumulated_days' => $c->accumulated_days,
                'termination_reason' => $c->termination_reason,
                'over_limit_reason' => $c->over_limit_reason,
                'allowed_actions' => array_values(array_diff($this->machine->manualActions($c->status), $successors->has($c->id) ? ['renew'] : [])),
                'version' => $c->lock_version,
                'created_at' => $c->created_at?->toIso8601String(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ];
        }, $contracts);
    }

    /**
     * Un contrato con su tiempo acumulado frente a los topes (RN-CON-02 / RN-CON-03) y la explicación.
     *
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array<string, mixed>
     */
    public function one(Contract $c, array $warnings = []): array
    {
        $data = $this->many([$c])[0];
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
        $data['accumulation'] = $this->terms->accumulation($c->employment_id, $c->contract_type_id, $c->valid_from->toDateString(),
            $c->valid_to?->toDateString(), $c->id, $country, false);
        $data['warnings'] = $warnings;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function addendum(ContractAddendum $a, ?string $employmentId = null): array
    {
        $changes = $a->changes ?? [];
        $salary = $changes['base_salary'] ?? null;
        unset($changes['base_salary']);

        return array_filter([
            'id' => $a->id,
            'contract_id' => $a->contract_id,
            'employment_id' => $employmentId ?? DB::table('contracts')->where('id', $a->contract_id)->value('employment_id'),
            'number' => $a->number,
            'kind' => $a->kind,
            'effective_on' => $a->effective_on->toDateString(),
            'description' => $a->description,
            'changes' => (object) $changes,
            'salary_change' => is_array($salary) ? ['amount' => (string) $salary['amount'], 'currency' => (string) $salary['currency']] : null,
            'status' => $a->status,
            'has_document' => $a->document_id !== null,
            'document_version' => $this->documents->version($a->document_id),
            'signed_at' => $a->signed_at?->toIso8601String(),
            'signature_origin' => $a->signature_origin,
            'applied_refs' => $a->applied_refs === null ? null : (object) $a->applied_refs,
            'cancellation_reason' => $a->cancellation_reason,
            'allowed_actions' => $this->amendments->allowedActions($a->status),
            'version' => $a->lock_version,
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ], fn ($v, $k) => $v !== null || ! in_array($k, ['salary_change'], true), ARRAY_FILTER_USE_BOTH);
    }
}
