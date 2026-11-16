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
 * HIS-2 · HIS-4: la aprobación, la programación a futuro y las correcciones las orquesta PersonnelActions;
 * aquí vive la mecánica de la asignación (validar, aplicar, corregir). Sueldo, contrato y régimen son de
 * HIS-3 y M-CON.
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
     * HIS-2 · Valida un movimiento sin escribir nada: la relación admite cambios, la fecha es posterior al
     * ingreso, no hay tramos desde esa fecha (409) y los valores resultantes son válidos en la estructura.
     *
     * @param  array<string, mixed>  $changes  subconjunto de FIELDS
     * @return array{from: string, kind: string, changed: list<string>, before: array<string, mixed>, after: array<string, mixed>, values: array<string, mixed>, current: \stdClass}
     */
    public function preview(Employment $employment, array $changes, string $validFrom, ?string $kind = null): array
    {
        if (! in_array($employment->status, EmploymentStateMachine::OPEN_STATES, true)) {
            throw new AyniException('invalid_transition', 409, (string) trans('people.movement_closed_relation'));
        }
        $from = EffectiveDated::day($validFrom);
        if ($from < $employment->hire_date->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('people.movement_before_hire')]]);
        }
        $current = $this->validity->rowAt('employment_assignments', ['employment_id' => $employment->id], $from);
        if ($current === null) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('people.movement_no_assignment')]]);
        }
        // Un tramo que ya empieza ese día o después no se pisa: se corrige (HIS-4) o se anula lo programado.
        if (DB::table('employment_assignments')->where('employment_id', $employment->id)->where('valid_from', '>=', $from)->exists()) {
            throw new AyniException('overlap', 409, (string) trans('validity.same_day'), [], ['reason' => 'same_day']);
        }

        return $this->compute($employment, $current, $changes, $from, $kind);
    }

    /**
     * Aplica el movimiento: deja (o completa) el personnel_action aplicado, cierra la asignación vigente el día
     * anterior e inserta la nueva, sincroniza las posiciones y emite employee.updated (y employee.promoted /
     * employee.transferred según el tipo).
     *
     * @param  array<string, mixed>  $changes
     * @return array{action_id: string, kind: string, changed: list<string>, assignment: array<string, mixed>}
     */
    public function applyChanges(Employment $employment, array $changes, string $validFrom, ?string $kind, ?string $reason, ?string $userId, ?string $actionId): array
    {
        return DB::transaction(function () use ($employment, $changes, $validFrom, $kind, $reason, $userId, $actionId): array {
            Employment::query()->whereKey($employment->id)->lockForUpdate()->first();
            $p = $this->preview($employment, $changes, $validFrom, $kind);
            $tenant = TenancyScope::requireTenant();
            $payload = json_encode(['before' => $p['before'], 'after' => $p['after']], JSON_THROW_ON_ERROR);
            if ($actionId === null) {
                $actionId = (string) DB::table('personnel_actions')->insertGetId([
                    'tenant_id' => $tenant, 'employment_id' => $employment->id, 'kind' => $p['kind'], 'effective_on' => $p['from'],
                    'reason' => $reason, 'status' => 'applied', 'requested_by_user_id' => $userId,
                    'applied_at' => now(), 'payload' => $payload, 'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                ]);
            } else {
                DB::table('personnel_actions')->where('id', $actionId)->update([
                    'kind' => $p['kind'], 'status' => 'applied', 'applied_at' => now(), 'payload' => $payload, 'updated_at' => now(),
                ]);
            }
            $id = $this->validity->change('employment_assignments', ['tenant_id' => $tenant, 'employment_id' => $employment->id],
                $p['values'] + ['reason' => $reason ?? $p['kind'], 'personnel_action_id' => $actionId], $p['from']);

            $this->afterChange($employment, $p['current']->position_id, $p['values']['position_id'] ?? null, $p['changed'], $p['from'], $actionId, $p['kind'], $userId);
            $this->audit->record('employment.assignment_changed', 'employment', $employment->id, $p['before'], $p['after'] + ['valid_from' => $p['from'], 'kind' => $p['kind']], $reason);

            /** @var array<string, mixed> $assignment */
            $assignment = $this->find($employment, $id);

            return ['action_id' => $actionId, 'kind' => $p['kind'], 'changed' => $p['changed'], 'assignment' => $assignment];
        });
    }

    /**
     * IMP-2 · RN-IMP-05 · Carga un movimiento histórico importado: mismas validaciones que el movimiento
     * manual (preview), queda aplicado y marcado «importado» en su payload, sincroniza las posiciones y
     * se audita, pero no emite eventos de negocio ni notificaciones. Devuelve lo necesario para deshacerlo.
     *
     * @param  array<string, mixed>  $changes
     * @return array{action_id: string, assignment_id: string, closed_id: string, closed_valid_to: string|null}
     */
    public function applyImported(Employment $employment, array $changes, string $validFrom, ?string $kind, ?string $reason, ?string $userId, string $importJobId): array
    {
        return DB::transaction(function () use ($employment, $changes, $validFrom, $kind, $reason, $userId, $importJobId): array {
            Employment::query()->whereKey($employment->id)->lockForUpdate()->first();
            $p = $this->preview($employment, $changes, $validFrom, $kind);
            $tenant = TenancyScope::requireTenant();
            $actionId = (string) DB::table('personnel_actions')->insertGetId([
                'tenant_id' => $tenant, 'employment_id' => $employment->id, 'kind' => $p['kind'], 'effective_on' => $p['from'],
                'reason' => $reason, 'status' => 'applied', 'requested_by_user_id' => $userId, 'applied_at' => now(),
                'payload' => json_encode(['before' => $p['before'], 'after' => $p['after'], 'imported' => true, 'import_job_id' => $importJobId], JSON_THROW_ON_ERROR),
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            ]);
            $closed = $p['current'];
            $id = $this->validity->change('employment_assignments', ['tenant_id' => $tenant, 'employment_id' => $employment->id],
                $p['values'] + ['reason' => $reason ?? $p['kind'], 'personnel_action_id' => $actionId], $p['from']);
            foreach (array_unique(array_filter([$closed->position_id, $p['values']['position_id'] ?? null])) as $positionId) {
                $this->occupancy->sync((string) $positionId, $userId);
            }
            $this->audit->record('employment.assignment_imported', 'employment', $employment->id, $p['before'], $p['after'] + ['valid_from' => $p['from'], 'kind' => $p['kind']], 'import:'.$importJobId);

            return ['action_id' => $actionId, 'assignment_id' => $id, 'closed_id' => (string) $closed->id, 'closed_valid_to' => $closed->valid_to === null ? null : (string) $closed->valid_to];
        });
    }

    /**
     * HIS-4 · RN-HIS-06 · Corrige el tramo que abrió un movimiento aplicado: solo el último tramo y abierto
     * (si hay movimientos posteriores, se corrigen o anulan primero esos). El valor original queda en la
     * historia del movimiento corregido (payload «after») y en la auditoría; el tramo toma los valores
     * correctos desde la misma fecha (EffectiveDated SAME_DAY_REPLACE) y queda enlazado al movimiento de
     * corrección. Debe correr dentro de la transacción de PersonnelActions.
     *
     * @param  array<string, mixed>  $changes
     * @return array{changed: list<string>, before: array<string, mixed>, after: array<string, mixed>, assignment: array<string, mixed>, from: string}
     */
    public function correctRow(Employment $employment, string $originalActionId, array $changes, string $correctionActionId, ?string $reason, ?string $userId): array
    {
        $row = DB::table('employment_assignments')->where('employment_id', $employment->id)->where('personnel_action_id', $originalActionId)->lockForUpdate()->first();
        if ($row === null) {
            throw new AyniException('invalid_transition', 409, (string) trans('people.movements.not_correctable'));
        }
        $later = DB::table('employment_assignments')->where('employment_id', $employment->id)->where('valid_from', '>', $row->valid_from)->exists();
        if ($later || $row->valid_to !== null) {
            throw new AyniException('later_movements', 409, (string) trans('people.movements.later_movements'));
        }
        $from = EffectiveDated::day((string) $row->valid_from);
        $p = $this->compute($employment, $row, $changes, $from, 'data_correction');
        $tenant = TenancyScope::requireTenant();
        $id = $this->validity->change('employment_assignments', ['tenant_id' => $tenant, 'employment_id' => $employment->id],
            $p['values'] + ['reason' => $reason ?? 'data_correction', 'personnel_action_id' => $correctionActionId, 'updated_at' => now()], $from, EffectiveDated::SAME_DAY_REPLACE);
        $this->afterChange($employment, $row->position_id, $p['values']['position_id'] ?? null, $p['changed'], $from, $correctionActionId, 'data_correction', $userId);
        $this->audit->record('employment.assignment_corrected', 'employment', $employment->id, $p['before'], $p['after'] + ['valid_from' => $from, 'corrects' => $originalActionId], $reason);

        /** @var array<string, mixed> $assignment */
        $assignment = $this->find($employment, $id);

        return ['changed' => $p['changed'], 'before' => $p['before'], 'after' => $p['after'], 'assignment' => $assignment, 'from' => $from];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{from: string, kind: string, changed: list<string>, before: array<string, mixed>, after: array<string, mixed>, values: array<string, mixed>, current: \stdClass}
     */
    private function compute(Employment $employment, \stdClass $current, array $changes, string $from, ?string $kind): array
    {
        $merged = [];
        foreach (self::FIELDS as $field) {
            $merged[$field] = $current->{$field};
        }
        $positionChanged = array_key_exists('position_id', $changes) && $changes['position_id'] !== $current->position_id;
        if ($positionChanged && is_string($changes['position_id'])) {
            // La posición nueva trae su puesto, unidad, sede y centro, salvo que el movimiento los indique.
            $position = DB::table('positions')->where('id', $changes['position_id'])->whereNull('deleted_at')->first();
            if ($position !== null) {
                foreach (['job_profile_id', 'org_unit_id', 'site_id', 'cost_center_id'] as $field) {
                    $merged[$field] = $position->{$field};
                }
            }
        }
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $changes)) {
                $merged[$field] = $changes[$field];
            }
        }

        $values = $this->employments->buildAssignment($merged, $employment->legal_entity_id, $from, $employment->id);
        $changed = array_values(array_filter(self::FIELDS, fn (string $f) => (string) ($values[$f] ?? '') !== (string) ($current->{$f} ?? '')));
        if ($changed === []) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('people.movement_no_changes')]]);
        }

        return [
            'from' => $from,
            'kind' => $kind ?? $this->kindFor($changed),
            'changed' => $changed,
            'before' => array_intersect_key((array) $current, array_flip($changed)),
            'after' => array_intersect_key($values, array_flip($changed)),
            'values' => $values,
            'current' => $current,
        ];
    }

    /**
     * Ocupación de las posiciones y eventos de un cambio de asignación.
     *
     * @param  list<string>  $changed
     */
    private function afterChange(Employment $employment, mixed $oldPosition, mixed $newPosition, array $changed, string $from, string $actionId, string $kind, ?string $userId): void
    {
        foreach (array_unique(array_filter([$oldPosition, $newPosition])) as $positionId) {
            $this->occupancy->sync((string) $positionId, $userId);
        }
        $base = ['employee_id' => $employment->id, 'employment_id' => $employment->id, 'person_id' => $employment->person_id,
            'effective_on' => $from, 'personnel_action_id' => $actionId, 'kind' => $kind, 'updated_fields' => $changed];
        $this->outbox->record(DomainEventTypes::EMPLOYEE_UPDATED, 'employment', $employment->id, $base);
        // HIS-2 · La promoción y el traslado quedan identificados como tales para la analítica.
        if ($kind === 'promotion') {
            $this->outbox->record(DomainEventTypes::EMPLOYEE_PROMOTED, 'employment', $employment->id, $base);
        } elseif ($kind === 'transfer') {
            $this->outbox->record(DomainEventTypes::EMPLOYEE_TRANSFERRED, 'employment', $employment->id, $base);
        }
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
