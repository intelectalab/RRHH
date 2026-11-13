<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Domain\EmploymentStateMachine;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Organization\PositionOccupancy;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * COL-5 · Los datos laborales de la ficha no se editan en sitio: se registra un movimiento con fecha
 * efectiva (regla 2). El movimiento deja un personnel_action aplicado y cierra la asignación vigente el
 * día anterior para insertar la nueva (EffectiveDated). La historia se lee de employment_assignments.
 *
 * Los movimientos con aprobación, los de sueldo, contrato o régimen y las correcciones retroactivas con
 * planilla cerrada son de M-HIS (HIS-2 a HIS-4); aquí solo los de la asignación.
 */
final class AssignmentMovements
{
    /** Clases de movimiento de la asignación (subconjunto de personnel_actions.kind). */
    public const KINDS = ['promotion', 'transfer', 'manager_change', 'cost_center_change', 'data_correction'];

    /** Columnas de valor de la asignación que un movimiento puede cambiar. */
    public const FIELDS = ['job_profile_id', 'position_id', 'org_unit_id', 'site_id', 'cost_center_id', 'manager_employment_id', 'work_modality'];

    public function __construct(
        private readonly EmploymentService $employments,
        private readonly EffectiveDated $validity,
        private readonly PositionOccupancy $occupancy,
        private readonly OutboxWriter $outbox,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Historia de la asignación (de la más reciente a la más antigua) o, con $asOf, solo la vigente a esa fecha.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(Employment $employment, ?string $asOf = null): array
    {
        $query = DB::table('employment_assignments')->where('employment_id', $employment->id);
        if ($asOf !== null) {
            EffectiveDated::applyAsOf($query, $asOf);
        }

        return $this->present($query->orderByDesc('valid_from')->get()->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(Employment $employment, string $id): ?array
    {
        $row = DB::table('employment_assignments')->where('employment_id', $employment->id)->where('id', $id)->first();

        return $row === null ? null : $this->present([$row])[0];
    }

    /**
     * Registra un movimiento de la asignación desde $input['valid_from'].
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> la asignación nueva
     */
    public function register(Employment $employment, array $input, ?string $userId): array
    {
        if (! in_array($employment->status, EmploymentStateMachine::OPEN_STATES, true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('people.movement_closed_relation'));
        }
        $from = EffectiveDated::day((string) $input['valid_from']);
        if ($from < $employment->hire_date->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('people.movement_before_hire')]]);
        }

        return DB::transaction(function () use ($employment, $input, $from, $userId): array {
            Employment::query()->whereKey($employment->id)->lockForUpdate()->first();
            $current = $this->validity->rowAt('employment_assignments', ['employment_id' => $employment->id], $from, true);
            if ($current === null) {
                throw ValidationException::withMessages(['valid_from' => [(string) trans('people.movement_no_assignment')]]);
            }

            $merged = [];
            foreach (self::FIELDS as $field) {
                $merged[$field] = $current->{$field};
            }
            $positionChanged = array_key_exists('position_id', $input) && $input['position_id'] !== $current->position_id;
            if ($positionChanged && is_string($input['position_id'])) {
                // La posición nueva trae su puesto, unidad, sede y centro, salvo que el movimiento los indique.
                $position = DB::table('positions')->where('id', $input['position_id'])->whereNull('deleted_at')->first();
                if ($position !== null) {
                    foreach (['job_profile_id', 'org_unit_id', 'site_id', 'cost_center_id'] as $field) {
                        $merged[$field] = $position->{$field};
                    }
                }
            }
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $input)) {
                    $merged[$field] = $input[$field];
                }
            }

            $values = $this->employments->buildAssignment($merged, $employment->legal_entity_id, $from, $employment->id);
            $changed = array_values(array_filter(self::FIELDS, fn (string $f) => (string) ($values[$f] ?? '') !== (string) ($current->{$f} ?? '')));
            if ($changed === []) {
                throw ValidationException::withMessages(['valid_from' => [(string) trans('people.movement_no_changes')]]);
            }
            $kind = isset($input['kind']) && is_string($input['kind']) ? $input['kind'] : $this->kindFor($changed);
            $tenant = TenancyScope::requireTenant();
            $before = array_intersect_key((array) $current, array_flip($changed));
            $after = array_intersect_key($values, array_flip($changed));

            $actionId = (string) DB::table('personnel_actions')->insertGetId([
                'tenant_id' => $tenant, 'employment_id' => $employment->id, 'kind' => $kind, 'effective_on' => $from,
                'reason' => $input['reason'] ?? null, 'status' => 'applied', 'requested_by_user_id' => $userId,
                'applied_at' => now(), 'payload' => json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR),
            ]);
            $id = $this->validity->change('employment_assignments', ['tenant_id' => $tenant, 'employment_id' => $employment->id],
                $values + ['reason' => $input['reason'] ?? $kind, 'personnel_action_id' => $actionId], $from);

            foreach (array_unique(array_filter([$current->position_id, $values['position_id']])) as $positionId) {
                $this->occupancy->sync((string) $positionId, $userId);
            }
            $this->outbox->record(DomainEventTypes::EMPLOYEE_UPDATED, 'employment', $employment->id, [
                'employee_id' => $employment->id, 'employment_id' => $employment->id, 'person_id' => $employment->person_id,
                'updated_fields' => $changed, 'effective_on' => $from, 'personnel_action_id' => $actionId, 'kind' => $kind,
            ]);
            $this->audit->record('employment.assignment_changed', 'employment', $employment->id, $before, $after + ['valid_from' => $from, 'kind' => $kind],
                isset($input['reason']) && is_string($input['reason']) ? $input['reason'] : null);

            /** @var array<string, mixed> */
            return $this->find($employment, $id);
        });
    }

    /**
     * @param  array<int, string>  $changed
     */
    private function kindFor(array $changed): string
    {
        return match (true) {
            in_array('job_profile_id', $changed, true) => 'promotion',
            (bool) array_intersect(['org_unit_id', 'site_id', 'position_id'], $changed) => 'transfer',
            in_array('manager_employment_id', $changed, true) => 'manager_change',
            in_array('cost_center_id', $changed, true) => 'cost_center_change',
            default => 'data_correction',
        };
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function present(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = fn (string $field) => array_values(array_unique(array_filter(array_map(fn ($r) => $r->{$field}, $rows))));
        $names = fn (string $table, string $col, array $keys) => $keys === [] ? collect() : DB::table($table)->whereIn('id', $keys)->pluck($col, 'id');
        $profiles = $names('job_profiles', 'name', $ids('job_profile_id'));
        $positions = $names('positions', 'title', $ids('position_id'));
        $units = $names('org_units', 'name', $ids('org_unit_id'));
        $sites = $names('sites', 'name', $ids('site_id'));
        $centers = $names('cost_centers', 'name', $ids('cost_center_id'));
        $managerIds = $ids('manager_employment_id');
        $managers = $managerIds === [] ? collect() : DB::table('employments as m')->join('people as p', 'p.id', '=', 'm.person_id')
            ->whereIn('m.id', $managerIds)->get(['m.id', 'p.first_names', 'p.preferred_name', 'p.paternal_surname', 'p.maternal_surname'])->keyBy('id');
        $actionIds = $ids('personnel_action_id');
        $kinds = $actionIds === [] ? collect() : DB::table('personnel_actions')->whereIn('id', $actionIds)->pluck('kind', 'id');

        return array_map(function (\stdClass $r) use ($profiles, $positions, $units, $sites, $centers, $managers, $kinds): array {
            $manager = $r->manager_employment_id === null ? null : $managers->get($r->manager_employment_id);

            return [
                'id' => (string) $r->id,
                'employment_id' => (string) $r->employment_id,
                'job_profile_id' => (string) $r->job_profile_id,
                'job_profile_name' => $profiles[$r->job_profile_id] ?? null,
                'position_id' => $r->position_id,
                'position_title' => $r->position_id === null ? null : ($positions[$r->position_id] ?? null),
                'org_unit_id' => (string) $r->org_unit_id,
                'org_unit_name' => $units[$r->org_unit_id] ?? null,
                'site_id' => (string) $r->site_id,
                'site_name' => $sites[$r->site_id] ?? null,
                'cost_center_id' => $r->cost_center_id,
                'cost_center_name' => $r->cost_center_id === null ? null : ($centers[$r->cost_center_id] ?? null),
                'manager_employment_id' => $r->manager_employment_id,
                'manager_name' => $manager === null ? null : EmploymentPresenter::name($manager),
                'work_modality' => (string) $r->work_modality,
                'valid_from' => (string) $r->valid_from,
                'valid_to' => $r->valid_to === null ? null : (string) $r->valid_to,
                'reason' => $r->reason,
                'personnel_action_id' => $r->personnel_action_id,
                'kind' => $r->personnel_action_id === null ? ($r->reason === 'hire' ? 'hire' : null) : ($kinds[$r->personnel_action_id] ?? null),
                'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
                'updated_at' => Carbon::parse((string) $r->updated_at)->toIso8601String(),
            ];
        }, $rows);
    }
}
