<?php

namespace App\Modules\CoreHR\Application;

use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\CoreHR\Infrastructure\Models\PersonnelAction;
use App\Shared\Permissions\EffectivePermissions;
use Illuminate\Support\Facades\DB;

/**
 * HIS-5 · Línea de tiempo laboral de una relación: ingreso o reingreso, movimientos (con el antes y el después
 * de cada campo), cambios de sueldo, contratos y adendas, estados (suspensión, reanudación, anulación) y cese.
 *
 * Se arma con los registros con vigencia y los de los demás módulos (no hay una tabla propia que copie
 * datos). Cada tipo exige el permiso de su recurso; los cambios de sueldo solo con
 * people.employments.salary.view (sin él no aparecen) y su lectura queda registrada por SensitiveFields.
 * La hora no importa: todo es por fecha laboral; del más reciente (o futuro programado) al más antiguo.
 */
final class EmploymentTimeline
{
    public const TYPES = ['hire', 'movement', 'compensation', 'contract', 'status', 'termination'];

    /** Permiso que además exige cada tipo (el de la ficha ya se validó). */
    private const PERMISSION = [
        'movement' => 'people.assignments.view',
        'compensation' => 'people.employments.salary.view',
        'contract' => 'contracts.contracts.view',
    ];

    /** Estados del movimiento que forman parte de la historia (programados incluidos: aún no rigen). */
    private const MOVEMENT_STATES = ['applied', 'scheduled', 'replaced'];

    public function __construct(
        private readonly PersonnelActions $actions,
        private readonly TerminationService $terminations,
    ) {}

    /**
     * Tipos que el usuario puede ver en la línea de tiempo.
     *
     * @return list<string>
     */
    public function visibleTypes(EffectivePermissions $permissions): array
    {
        return array_values(array_filter(self::TYPES, fn (string $t) => ! isset(self::PERMISSION[$t]) || $permissions->has(self::PERMISSION[$t])));
    }

    /**
     * @param  list<string>  $types  tipos pedidos (ya filtrados por permiso)
     * @return list<array<string, mixed>>
     */
    public function events(Employment $employment, array $types, EffectivePermissions $permissions): array
    {
        $events = [];
        if (in_array('hire', $types, true)) {
            $events[] = $this->hire($employment);
        }
        if (in_array('movement', $types, true)) {
            array_push($events, ...$this->movements($employment));
        }
        if (in_array('compensation', $types, true)) {
            array_push($events, ...$this->compensations($employment));
        }
        if (in_array('contract', $types, true)) {
            array_push($events, ...$this->contracts($employment, $permissions->has('contracts.addenda.view')));
        }
        if (in_array('status', $types, true)) {
            array_push($events, ...$this->statuses($employment));
        }
        if (in_array('termination', $types, true)) {
            $termination = $this->termination($employment);
            if ($termination !== null) {
                $events[] = $termination;
            }
        }
        // Más reciente primero; el mismo día, en el orden en que ocurrió (ingreso antes que su contrato, etc.).
        usort($events, fn (array $a, array $b) => [$b['date'], $b['order']] <=> [$a['date'], $a['order']]);

        return array_map(function (array $e): array {
            unset($e['order']);

            return $e;
        }, $events);
    }

    /**
     * @return array<string, mixed>
     */
    private function hire(Employment $employment): array
    {
        $entity = DB::table('legal_entities')->where('id', $employment->legal_entity_id)->first(['legal_name', 'trade_name']);
        $previous = $employment->rehire_of_employment_id === null ? null
            : DB::table('employments')->where('id', $employment->rehire_of_employment_id)->first(['id', 'termination_date']);

        return $this->event('hire', $employment->rehire_of_employment_id === null ? 'hire' : 'rehire', $employment->hire_date->toDateString(), 0, [
            'legal_entity_name' => $entity === null ? null : ($entity->trade_name ?: $entity->legal_name),
            'employee_code' => $employment->employee_code,
            'previous_employment_id' => $previous?->id,
            'previous_termination_date' => $previous?->termination_date === null ? null : (string) $previous->termination_date,
        ], [], null, 'employment', $employment->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movements(Employment $employment): array
    {
        /** @var list<PersonnelAction> $rows */
        $rows = PersonnelAction::query()->where('employment_id', $employment->id)->whereIn('status', self::MOVEMENT_STATES)
            // El ingreso y los cambios de sueldo se muestran con su propio tipo (el sueldo, solo con permiso de campo).
            ->whereNotIn('kind', ['hire', 'salary_change'])->orderBy('effective_on')->orderBy('created_at')->get()->all();
        $out = [];
        foreach ($rows as $action) {
            $p = $this->actions->present($action);
            $changes = [];
            foreach ((array) $p['after'] as $field => $after) {
                $changes[] = ['field' => $field, 'before' => $p['before'][$field] ?? ['value' => null, 'label' => null], 'after' => $after];
            }
            $out[] = $this->event('movement', (string) $action->kind, (string) $p['effective_on'], 2, [
                'is_retroactive' => (bool) $p['is_retroactive'],
                'replaced_by_personnel_action_id' => $p['replaced_by_personnel_action_id'],
                'replaces_personnel_action_id' => $p['replaces_personnel_action_id'],
            ], $changes, $p['reason'] === null ? null : (string) $p['reason'], 'personnel_action', $action->id, (string) $action->status);
        }

        return $out;
    }

    /**
     * Cada tramo de sueldo frente al anterior. El primero es el sueldo de ingreso (sin «antes»).
     *
     * @return list<array<string, mixed>>
     */
    private function compensations(Employment $employment): array
    {
        $rows = DB::table('employment_compensations')->where('employment_id', $employment->id)
            ->orderBy('valid_from')->get(['id', 'base_salary', 'currency', 'pay_frequency', 'change_reason', 'valid_from', 'personnel_action_id']);
        $out = [];
        $previous = null;
        foreach ($rows as $row) {
            $after = ['amount' => (string) $row->base_salary, 'currency' => (string) $row->currency];
            $before = $previous === null ? null : ['amount' => (string) $previous->base_salary, 'currency' => (string) $previous->currency];
            $event = $this->event('compensation', $row->change_reason === null ? ($previous === null ? 'hire' : 'adjustment') : (string) $row->change_reason,
                (string) $row->valid_from, 3, [
                    'pay_frequency' => (string) $row->pay_frequency,
                    'previous_pay_frequency' => $previous === null ? null : (string) $previous->pay_frequency,
                    'personnel_action_id' => $row->personnel_action_id === null ? null : (string) $row->personnel_action_id,
                ], [], null, 'compensation', (string) $row->id);
            $event['salary'] = ['before' => $before, 'after' => $after];
            $out[] = $event;
            $previous = $row;
        }

        return $out;
    }

    /**
     * Inicio de cada contrato (o de su renovación), su fin si ya terminó o venció sin regularizar, y las
     * adendas firmadas. El sueldo pactado en una adenda no viaja aquí (está en la pestaña de contratos con su
     * permiso de campo); solo el tipo de cambio y la descripción.
     *
     * @return list<array<string, mixed>>
     */
    private function contracts(Employment $employment, bool $withAddenda): array
    {
        $rows = DB::table('contracts as c')->join('contract_types as t', 't.id', '=', 'c.contract_type_id')
            ->where('c.employment_id', $employment->id)->whereNull('c.deleted_at')
            ->whereNotIn('c.status', ['draft', 'pending_approval', 'cancelled'])
            ->orderBy('c.valid_from')->get(['c.id', 'c.number', 'c.status', 'c.valid_from', 'c.valid_to', 'c.renewal_of_contract_id', 'c.termination_reason', 't.name as type_name']);
        $out = [];
        foreach ($rows as $c) {
            $args = ['number' => $c->number, 'contract_type_name' => (string) $c->type_name, 'ends_on' => $c->valid_to === null ? null : (string) $c->valid_to];
            $kind = $c->renewal_of_contract_id === null ? 'contract_start' : 'contract_renewal';
            $out[] = $this->event('contract', $kind, (string) $c->valid_from, 1, $args, [], null, 'contract', (string) $c->id, (string) $c->status);
            if ($c->valid_to !== null && in_array($c->status, ['terminated', 'expired'], true) && $c->termination_reason !== 'renewed') {
                $end = $c->status === 'expired' ? 'contract_expired' : 'contract_end';
                $out[] = $this->event('contract', $end, (string) $c->valid_to, 5, $args, [], $c->status === 'terminated' ? ($c->termination_reason === null ? null : (string) $c->termination_reason) : null,
                    'contract', (string) $c->id, (string) $c->status);
            }
        }
        if ($withAddenda && $rows->isNotEmpty()) {
            $addenda = DB::table('contract_amendments')->whereIn('contract_id', $rows->pluck('id')->all())->whereNull('deleted_at')
                ->where('status', 'active')->orderBy('effective_on')->get(['id', 'contract_id', 'number', 'kind', 'effective_on', 'description']);
            foreach ($addenda as $a) {
                $out[] = $this->event('contract', 'addendum_'.$a->kind, (string) $a->effective_on, 4, [
                    'number' => $a->number, 'contract_id' => (string) $a->contract_id,
                ], [], (string) $a->description, 'contract_addendum', (string) $a->id, 'active');
            }
        }

        return $out;
    }

    /**
     * Cambios de estado que no son el ingreso ni el cese: suspensión, reanudación y anulación del ingreso.
     *
     * @return list<array<string, mixed>>
     */
    private function statuses(Employment $employment): array
    {
        $rows = DB::table('employment_status_events')->where('employment_id', $employment->id)
            ->orderBy('effective_on')->orderBy('created_at')->get(['id', 'from_status', 'to_status', 'effective_on', 'notes']);
        $out = [];
        foreach ($rows as $row) {
            $kind = match (true) {
                $row->to_status === 'suspended' => 'suspended',
                $row->from_status === 'suspended' && $row->to_status === 'active' => 'reinstated',
                $row->to_status === 'cancelled' => 'cancelled',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            $out[] = $this->event('status', $kind, (string) $row->effective_on, 6, [
                'from_status' => $row->from_status === null ? null : (string) $row->from_status, 'to_status' => (string) $row->to_status,
            ], [], $row->notes === null ? null : (string) $row->notes, 'employment_status_event', (string) $row->id);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function termination(Employment $employment): ?array
    {
        $t = $this->terminations->current($employment, false);
        if ($t === null) {
            return null;
        }

        return $this->event('termination', 'termination', (string) $t['termination_date'], 7, [
            'reason_name' => $t['reason_name'], 'last_working_day' => $t['last_working_day'],
        ], [], null, 'termination', (string) $t['id'], $t['status'] === 'executed' ? 'applied' : 'scheduled');
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  list<array{field: string, before: mixed, after: mixed}>  $changes
     * @return array<string, mixed>
     */
    private function event(string $type, string $kind, string $date, int $order, array $args, array $changes, ?string $reason, string $refType, string $refId, string $status = 'applied'): array
    {
        return [
            'id' => $refType.':'.$refId.':'.$kind,
            'date' => substr($date, 0, 10),
            'type' => $type,
            'kind' => $kind,
            'status' => $status,
            'args' => (object) array_filter($args, fn ($v) => $v !== null),
            'changes' => $changes,
            'reason' => $reason,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'order' => $order,
        ];
    }
}
