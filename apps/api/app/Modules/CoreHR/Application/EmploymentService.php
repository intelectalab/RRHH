<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\EmploymentStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\Person;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\UsageLimits;
use App\Shared\Usage\UsageMetrics;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-2 · Alta de relación laboral (ME-01).
 *
 * El ingreso crea, en una sola transacción y con la misma fecha de inicio, la relación laboral, su primera
 * asignación (puesto, posición, unidad, sede, centro, jefe), su régimen laboral, su contrato inicial (en
 * borrador hasta la firma, CON-2) y, si quien registra puede editar sueldos, su compensación. Emite
 * employee.hired una sola vez. Con fecha futura queda «Por ingresar» y se activa ese día (RN-COL-04).
 *
 * Reglas: RN-COL-01 (persona única, PersonService), RN-COL-02 (sin dos relaciones abiertas con la misma
 * empresa), RN-COL-13 (límite del plan) y posiciones.contratar_solo_con_posicion (el alta consume una
 * posición vacante). Estructura y posición se validan con los contratos de Organización.
 */
final class EmploymentService
{
    public function __construct(
        private readonly PersonService $people,
        private readonly EmploymentStateMachine $machine,
        private readonly StructureAvailability $structure,
        private readonly PositionOccupancy $occupancy,
        private readonly UsageLimits $limits,
        private readonly ParameterResolver $parameters,
        private readonly EffectiveDated $validity,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  bool  $canSetSalary  people.employments.salary.edit
     */
    public function hire(array $input, ?string $userId, bool $canSetSalary): Employment
    {
        if (isset($input['base_salary']) && ! $canSetSalary) {
            throw ValidationException::withMessages(['base_salary' => [(string) trans('people.salary_forbidden')]]);
        }
        $hireDate = EffectiveDated::day((string) $input['hire_date']);
        $legalEntityId = (string) $input['legal_entity_id'];
        $today = now()->toDateString();

        return DB::transaction(function () use ($input, $userId, $hireDate, $legalEntityId, $today): Employment {
            $person = $this->resolvePerson($input);
            // Dos ingresos simultáneos de la misma persona se ordenan: el segundo ve al primero (RN-COL-02).
            Person::query()->whereKey($person->id)->lockForUpdate()->first();

            $assignment = $this->assignment($input, $legalEntityId, $hireDate);
            $previous = $this->assertNoOpenRelation($person, $legalEntityId, $hireDate);
            $status = $hireDate <= $today ? 'active' : 'pending';
            if ($status === 'active') {
                // RN-COL-13 · El límite cuenta relaciones activas; un ingreso futuro se valida al activarse.
                $this->limits->assertCanAdd(UsageMetrics::EMPLOYEES);
            }
            $regime = $this->regime($input, $legalEntityId, $hireDate);
            $contract = $this->contract($input, $hireDate);

            $employment = Employment::create([
                'person_id' => $person->id,
                'legal_entity_id' => $legalEntityId,
                'employee_code' => $this->employeeCode($input, $legalEntityId),
                'kind' => $input['kind'] ?? 'employee',
                'worker_category' => $input['worker_category'] ?? 'employee',
                'hire_date' => $hireDate,
                'seniority_date' => $input['seniority_date'] ?? null,
                'probation_ends_on' => $input['probation_ends_on'] ?? null,
                'status' => $status,
                'rehire_of_employment_id' => $previous,
                'work_email' => isset($input['work_email']) ? mb_strtolower(trim((string) $input['work_email'])) : null,
                'work_phone' => $input['work_phone'] ?? null,
                'work_phone_ext' => $input['work_phone_ext'] ?? null,
            ]);
            $tenant = TenancyScope::requireTenant();
            DB::table('employment_status_events')->insert([
                'tenant_id' => $tenant, 'employment_id' => $employment->id, 'from_status' => null, 'to_status' => $status,
                'reason_code' => $previous === null ? 'hire' : 'rehire', 'effective_on' => $hireDate, 'changed_by_user_id' => $userId,
            ]);

            $relation = ['tenant_id' => $tenant, 'employment_id' => $employment->id];
            $this->validity->change('employment_assignments', $relation, $assignment + ['reason' => 'hire'], $hireDate);
            $this->validity->change('employment_labor_regimes', $relation, $regime, $hireDate);
            DB::table('contracts')->insert($relation + $contract + ['valid_from' => $hireDate]);
            if (isset($input['base_salary']) && is_array($input['base_salary'])) {
                $this->validity->change('employment_compensations', $relation, [
                    'base_salary' => (string) $input['base_salary']['amount'],
                    'currency' => strtoupper((string) $input['base_salary']['currency']),
                    'pay_frequency' => $input['pay_frequency'] ?? 'monthly',
                    'change_reason' => 'hire',
                ], $hireDate);
            }
            if ($assignment['position_id'] !== null) {
                $this->occupancy->sync((string) $assignment['position_id'], $userId);
            }

            $this->outbox->record(DomainEventTypes::EMPLOYEE_HIRED, 'employment', $employment->id, [
                'employee_id' => $employment->id,
                'employment_id' => $employment->id,
                'person_id' => $person->id,
                'legal_entity_id' => $legalEntityId,
                'first_name' => $person->first_names,
                'last_name' => trim($person->paternal_surname.' '.($person->maternal_surname ?? '')),
                'hire_date' => $hireDate,
                'status' => $status,
                'position_id' => $assignment['position_id'],
                'rehire_of_employment_id' => $previous,
            ]);
            $this->audit->record('employment.hired', 'employment', $employment->id, null, [
                'person_id' => $person->id, 'legal_entity_id' => $legalEntityId, 'employee_code' => $employment->employee_code,
                'hire_date' => $hireDate, 'status' => $status, 'position_id' => $assignment['position_id'],
                'base_salary' => $input['base_salary'] ?? null,
            ]);

            return $employment->refresh();
        });
    }

    /**
     * Por ingresar → Activa (manual con people.employments.activate, o por la fecha). Exige la fecha de
     * ingreso alcanzada y cupo en el plan.
     */
    public function activate(Employment $employment, ?string $userId, string $source = 'manual'): Employment
    {
        $this->machine->getNextState($employment->status, 'activate');
        if ($employment->hire_date->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages(['hire_date' => [(string) trans('people.hire_date_not_reached', ['date' => $employment->hire_date->toDateString()])]]);
        }
        $this->limits->assertCanAdd(UsageMetrics::EMPLOYEES);

        return $this->move($employment, 'activate', $userId, $source === 'system' ? 'hire_date_reached' : null, null,
            DomainEventTypes::EMPLOYMENT_ACTIVATED);
    }

    /**
     * Por ingresar → Anulada: el ingreso no ocurrió. La posición queda libre (la asignación deja de apuntarla)
     * y el contrato en borrador se cancela; la persona y su historia se conservan (RN-COL-03).
     */
    public function cancel(Employment $employment, ?string $reason, ?string $userId): Employment
    {
        $this->machine->getNextState($employment->status, 'cancel');
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('people.reason_required')]]);
        }

        return DB::transaction(function () use ($employment, $reason, $userId): Employment {
            $positions = DB::table('employment_assignments')->where('employment_id', $employment->id)->whereNotNull('position_id')->pluck('position_id')->all();
            // Corrección de un ingreso que nunca rigió: la asignación deja de ocupar la plaza.
            DB::table('employment_assignments')->where('employment_id', $employment->id)->update(['position_id' => null, 'reason' => 'hire_cancelled']);
            DB::table('contracts')->where('employment_id', $employment->id)->whereIn('status', ['draft', 'pending_signature', 'pending_approval'])->update(['status' => 'cancelled']);
            $moved = $this->move($employment, 'cancel', $userId, 'hire_cancelled', $reason, DomainEventTypes::EMPLOYEE_HIRE_CANCELLED);
            foreach (array_unique($positions) as $positionId) {
                $this->occupancy->sync((string) $positionId, $userId);
            }

            return $moved;
        });
    }

    /**
     * RN-COL-04 · Activa los ingresos cuya fecha llegó. Un ingreso que no puede activarse (p. ej. sin cupo
     * en el plan) queda «Por ingresar» y se informa; no detiene a los demás.
     *
     * @return array{activated: int, blocked: array<int, string>}
     */
    public function activateDue(): array
    {
        $activated = 0;
        $blocked = [];
        $due = Employment::query()->where('status', 'pending')->whereDate('hire_date', '<=', now()->toDateString())->orderBy('hire_date')->get();
        foreach ($due as $employment) {
            try {
                $this->activate($employment, null, 'system');
                $activated++;
            } catch (AyniException $e) {
                $blocked[] = $employment->id.': '.$e->getMessage();
            }
        }

        return ['activated' => $activated, 'blocked' => $blocked];
    }

    /**
     * @return array<int, string>
     */
    public function allowedActions(Employment $employment): array
    {
        return $this->machine->manualActions($employment->status);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolvePerson(array $input): Person
    {
        if (isset($input['person_id']) && is_string($input['person_id'])) {
            $person = Person::query()->find($input['person_id']);
            if (! $person instanceof Person) {
                throw ValidationException::withMessages(['person_id' => [(string) trans('people.person_not_found')]]);
            }

            return $person;
        }

        /** @var array<string, mixed> $data */
        $data = (array) ($input['person'] ?? []);

        return $this->people->create($data);
    }

    /**
     * Asignación inicial: si viene una posición, lo que falte se toma de ella (puesto, unidad, sede, centro).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function assignment(array $input, string $legalEntityId, string $hireDate): array
    {
        $this->structure->assertLegalEntityAssignable($legalEntityId);
        $positionId = isset($input['position_id']) && is_string($input['position_id']) ? $input['position_id'] : null;
        if ($positionId === null && $this->parameters->value('posiciones.contratar_solo_con_posicion', $legalEntityId) === true) {
            throw ValidationException::withMessages(['position_id' => [(string) trans('people.position_required')]]);
        }
        $fromPosition = [];
        if ($positionId !== null) {
            $position = DB::table('positions')->where('id', $positionId)->whereNull('deleted_at')->first();
            if ($position === null) {
                throw ValidationException::withMessages(['position_id' => [(string) trans('people.position_not_found')]]);
            }
            if ((string) $position->legal_entity_id !== $legalEntityId) {
                throw ValidationException::withMessages(['position_id' => [(string) trans('people.position_other_entity')]]);
            }
            $this->occupancy->assertAssignable($positionId, $hireDate);
            $fromPosition = [
                'job_profile_id' => $position->job_profile_id, 'org_unit_id' => $position->org_unit_id,
                'site_id' => $position->site_id, 'cost_center_id' => $position->cost_center_id,
            ];
        }
        $pick = fn (string $key): ?string => isset($input[$key]) && is_string($input[$key]) && $input[$key] !== ''
            ? $input[$key] : (isset($fromPosition[$key]) ? (string) $fromPosition[$key] : null);

        $values = [
            'job_profile_id' => $pick('job_profile_id'),
            'position_id' => $positionId,
            'org_unit_id' => $pick('org_unit_id'),
            'site_id' => $pick('site_id'),
            'cost_center_id' => $pick('cost_center_id'),
            'manager_employment_id' => isset($input['manager_employment_id']) && is_string($input['manager_employment_id']) ? $input['manager_employment_id'] : null,
            'work_modality' => $input['work_modality'] ?? 'onsite',
        ];
        $missing = [];
        foreach (['job_profile_id', 'org_unit_id', 'site_id'] as $required) {
            if ($values[$required] === null) {
                $missing[$required] = [(string) trans('people.field_required')];
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
        $this->structure->assertJobProfileAssignable((string) $values['job_profile_id']);
        $this->structure->assertOrgUnitAssignable((string) $values['org_unit_id']);
        $this->structure->assertSiteAssignable((string) $values['site_id'], $legalEntityId);
        if ($values['cost_center_id'] !== null) {
            $this->structure->assertCostCenterAssignable((string) $values['cost_center_id'], $hireDate, $legalEntityId);
        }
        if ($values['manager_employment_id'] !== null && ! Employment::query()->whereKey($values['manager_employment_id'])
            ->whereIn('status', EmploymentStateMachine::OPEN_STATES)->exists()) {
            throw ValidationException::withMessages(['manager_employment_id' => [(string) trans('people.manager_invalid')]]);
        }

        return $values;
    }

    /**
     * RN-COL-02 · Sin otra relación abierta con la misma empresa. Devuelve la relación cesada anterior con
     * esa empresa (reingreso), que no se reabre.
     */
    private function assertNoOpenRelation(Person $person, string $legalEntityId, string $hireDate): ?string
    {
        $open = Employment::query()->where('person_id', $person->id)->where('legal_entity_id', $legalEntityId)
            ->whereIn('status', EmploymentStateMachine::OPEN_STATES)->first();
        if ($open instanceof Employment) {
            throw new AyniException('employment_overlap', 409, (string) trans('people.employment_overlap', ['code' => $open->employee_code]), [], [
                'employment_id' => $open->id,
            ]);
        }
        $last = Employment::query()->where('person_id', $person->id)->where('legal_entity_id', $legalEntityId)
            ->where('status', 'terminated')->orderByDesc('termination_date')->first();
        if ($last instanceof Employment && $last->termination_date !== null && $last->termination_date->toDateString() >= $hireDate) {
            throw ValidationException::withMessages(['hire_date' => [(string) trans('people.rehire_before_termination', ['date' => $last->termination_date->toDateString()])]]);
        }

        return $last?->id;
    }

    /**
     * Régimen laboral inicial: el indicado o el vigente de la empresa a la fecha de ingreso.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function regime(array $input, string $legalEntityId, string $hireDate): array
    {
        $code = isset($input['labor_regime_code']) && is_string($input['labor_regime_code']) ? $input['labor_regime_code'] : null;
        $code ??= DB::table('legal_entity_regimes')->where('legal_entity_id', $legalEntityId)->where('valid_from', '<=', $hireDate)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $hireDate))->value('labor_regime_code');
        if (! is_string($code) || ! DB::table('labor_regimes')->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['labor_regime_code' => [(string) trans('people.regime_required')]]);
        }

        return ['labor_regime_code' => $code, 'health_scheme' => $input['health_scheme'] ?? 'essalud'];
    }

    /**
     * Contrato inicial en borrador: se genera, firma y activa en Contratos (CON-2).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function contract(array $input, string $hireDate): array
    {
        $type = DB::table('contract_types')->where('id', $input['contract_type_id'] ?? null)->whereNull('deleted_at')->first();
        if ($type === null) {
            throw ValidationException::withMessages(['contract_type_id' => [(string) trans('people.contract_type_invalid')]]);
        }
        $end = isset($input['contract_end_date']) && is_string($input['contract_end_date']) ? EffectiveDated::day($input['contract_end_date']) : null;
        if ((bool) $type->requires_end_date && $end === null) {
            throw ValidationException::withMessages(['contract_end_date' => [(string) trans('people.contract_end_required')]]);
        }
        if ($end !== null && $end < $hireDate) {
            throw ValidationException::withMessages(['contract_end_date' => [(string) trans('people.contract_end_before_hire')]]);
        }
        $cause = isset($input['contract_objective_cause']) && is_string($input['contract_objective_cause']) ? trim($input['contract_objective_cause']) : '';
        if ((bool) $type->requires_objective_cause && $cause === '') {
            throw ValidationException::withMessages(['contract_objective_cause' => [(string) trans('people.contract_cause_required')]]);
        }

        return [
            'contract_type_id' => (string) $type->id, 'status' => 'draft', 'valid_to' => $end,
            'objective_cause' => $cause === '' ? null : $cause, 'work_modality' => $input['work_modality'] ?? 'onsite',
        ];
    }

    /**
     * Código del colaborador: el indicado (único en la empresa) o el correlativo de la empresa si la
     * organización lo genera (colaboradores.codigo_automatico).
     *
     * @param  array<string, mixed>  $input
     */
    private function employeeCode(array $input, string $legalEntityId): string
    {
        $given = isset($input['employee_code']) && is_string($input['employee_code']) ? trim($input['employee_code']) : '';
        if ($given === '') {
            if ($this->parameters->value('colaboradores.codigo_automatico', $legalEntityId) === false) {
                throw ValidationException::withMessages(['employee_code' => [(string) trans('people.field_required')]]);
            }
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['employee-code:'.$legalEntityId]);
            $max = (int) DB::table('employments')->where('legal_entity_id', $legalEntityId)
                ->whereRaw("employee_code ~ '^[0-9]+$'")->max(DB::raw('employee_code::bigint'));

            return str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
        }
        if (Employment::query()->withTrashed()->where('legal_entity_id', $legalEntityId)->where('employee_code', $given)->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('people.code_exists', ['code' => $given]));
        }

        return $given;
    }

    private function move(Employment $employment, string $action, ?string $userId, ?string $reasonCode, ?string $notes, string $event): Employment
    {
        return DB::transaction(function () use ($employment, $action, $userId, $reasonCode, $notes, $event): Employment {
            /** @var Employment $locked */
            $locked = Employment::query()->whereKey($employment->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;
            $to = $this->machine->getNextState($from, $action);
            $locked->status = $to;
            $locked->lock_version++;
            $locked->save();
            DB::table('employment_status_events')->insert([
                'tenant_id' => $locked->tenant_id, 'employment_id' => $locked->id, 'from_status' => $from, 'to_status' => $to,
                'reason_code' => $reasonCode, 'effective_on' => now()->toDateString(), 'changed_by_user_id' => $userId, 'notes' => $notes,
            ]);
            $this->outbox->record($event, 'employment', $locked->id, [
                'employment_id' => $locked->id, 'person_id' => $locked->person_id, 'legal_entity_id' => $locked->legal_entity_id,
                'hire_date' => $locked->hire_date->toDateString(), 'from_status' => $from, 'to_status' => $to,
            ]);
            $this->audit->record('employment.'.$action, 'employment', $locked->id, ['status' => $from], ['status' => $to], $notes);

            return $locked->refresh();
        });
    }
}
