<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\Automation\RuleConditions;
use App\Modules\Workflow\Domain\AutomationRuleStateMachine;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WFL-5 · Alta, edición y estados de las reglas CUANDO → SI → ENTONCES.
 * Se valida al guardar que el evento exista, que las condiciones sean comparaciones declarativas y
 * que cada acción sea de la lista cerrada con sus datos completos.
 */
final class AutomationRuleService
{
    private const MAX_ACTIONS = 10;

    public function __construct(
        private readonly AutomationCatalog $catalog,
        private readonly AutomationRuleStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?string $userId = null): AutomationRule
    {
        $data = $this->validated($input, true);
        // WFL-7 · Toda regla tiene un responsable: quien la crea, salvo que se indique otro usuario.
        $data['owner_user_id'] ??= $userId;

        return DB::transaction(function () use ($data): AutomationRule {
            $rule = AutomationRule::create($data + ['status' => 'draft']);
            $this->audit->record('automation_rule.created', 'automation_rule', $rule->id, null, $this->snapshot($rule->refresh()));

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(AutomationRule $rule, array $input): AutomationRule
    {
        $this->assertNotManagedByFlow($rule, array_diff(array_keys($input), ['owner_user_id']) !== []);
        $changesBehaviour = (array_key_exists('trigger_event', $input) && $input['trigger_event'] !== $rule->trigger_event)
            || array_key_exists('conditions', $input) || array_key_exists('actions', $input);
        $data = $this->validated($input + ['trigger_event' => $rule->trigger_event], false);
        // Una regla activa no cambia de comportamiento en caliente: se pausa, se edita y se reactiva.
        if ($changesBehaviour && $rule->status === 'active') {
            throw new BusinessRuleViolatedException('RN-WFL-09', (string) trans('workflow.automation.not_editable'));
        }

        return DB::transaction(function () use ($rule, $data): AutomationRule {
            $before = $this->snapshot($rule);
            $rule->fill($data)->save();
            $this->audit->record('automation_rule.updated', 'automation_rule', $rule->id, $before, $this->snapshot($rule->refresh()));

            return $rule;
        });
    }

    public function transition(AutomationRule $rule, string $action): AutomationRule
    {
        // Un flujo del constructor se pausa y reanuda también desde aquí; retirarlo es archivar el flujo.
        $this->assertNotManagedByFlow($rule, $action === 'retire');
        $next = $this->machine->getNextState($rule->status, $action);

        return DB::transaction(function () use ($rule, $action, $next): AutomationRule {
            $before = $this->snapshot($rule);
            if ($next === 'retired') {
                $rule->forceFill(['status' => 'paused'])->save();
                $rule->delete();
            } else {
                $rule->forceFill(['status' => $next] + ($next === 'active' ? ['failing_since' => null] : []))->save();
            }
            if ($rule->workflow_definition_id !== null) {
                DB::table('workflow_definitions')->where('id', $rule->workflow_definition_id)->where('status', '!=', 'archived')
                    ->update(['status' => $next === 'active' ? 'active' : 'inactive', 'updated_at' => now()]);
            }
            $this->audit->record("automation_rule.{$action}", 'automation_rule', $rule->id, $before, ['status' => $next]);

            return $rule;
        });
    }

    /**
     * WFL-6 · El comportamiento de una regla creada por el constructor visual se edita en el constructor.
     */
    private function assertNotManagedByFlow(AutomationRule $rule, bool $changes): void
    {
        if ($changes && $rule->workflow_definition_id !== null) {
            throw new AyniException('managed_by_flow', 409, (string) trans('workflow.flows.managed_by_flow'));
        }
    }

    /**
     * Pausa automática por protección contra bucles (RN-WFL-11).
     */
    public function autoPause(AutomationRule $rule, int $max): void
    {
        if ($rule->status !== 'active') {
            return;
        }
        $rule->forceFill(['status' => 'paused'])->save();
        $this->audit->record('automation_rule.auto_paused', 'automation_rule', $rule->id, ['status' => 'active'], ['status' => 'paused'], (string) trans('workflow.automation.loop_paused', ['max' => $max]));
    }

    /**
     * @param  array<string, mixed>|null  $stats
     * @return array<string, mixed>
     */
    public function present(AutomationRule $rule, ?array $stats = null): array
    {
        $status = $rule->trashed() ? 'retired' : $rule->status;
        $owner = $rule->owner_user_id === null ? null : DB::table('users')->where('id', $rule->owner_user_id)->value('display_name');

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'trigger_event' => $rule->trigger_event,
            'conditions' => (object) ($rule->conditions ?? ['match' => 'all', 'rules' => []]),
            'actions' => $rule->actions,
            'status' => $status,
            'allowed_actions' => $rule->trashed() ? [] : $this->machine->allowedActions($rule->status),
            'last_run_at' => $rule->last_run_at?->toIso8601String(),
            // WFL-7 · Las reglas reaccionan a eventos: no hay próxima ejecución programada salvo que la regla tenga horario.
            'next_run_at' => $rule->next_run_at?->toIso8601String(),
            'owner_user_id' => $rule->owner_user_id,
            'owner_name' => is_string($owner) ? $owner : null,
            'failing' => $rule->failing_since !== null,
            'failing_since' => $rule->failing_since?->toIso8601String(),
            'managed_by_flow' => $rule->workflow_definition_id,
            'stats' => (object) ($stats ?? $this->stats([$rule->id])[$rule->id] ?? []),
            'version' => $rule->lock_version,
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    /**
     * WFL-7 · Ejecuciones y errores de los últimos 30 días por regla, y el último error.
     *
     * @param  list<string>  $ruleIds
     * @return array<string, array{runs: int, failed: int, succeeded: int, skipped: int, last_error: string|null}>
     */
    public function stats(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }
        $rows = DB::table('automation_runs')->whereIn('automation_rule_id', $ruleIds)->where('started_at', '>=', now()->subDays(30))
            ->selectRaw("automation_rule_id, count(*) AS runs, count(*) FILTER (WHERE status = 'failed') AS failed, count(*) FILTER (WHERE status = 'succeeded') AS succeeded, count(*) FILTER (WHERE status = 'skipped') AS skipped")
            ->groupBy('automation_rule_id')->get()->keyBy('automation_rule_id');
        $out = [];
        foreach ($ruleIds as $id) {
            $row = $rows->get($id);
            $lastError = DB::table('automation_runs')->where('automation_rule_id', $id)->where('status', 'failed')->orderByDesc('started_at')->value('error');
            $out[$id] = [
                'runs' => (int) ($row->runs ?? 0), 'failed' => (int) ($row->failed ?? 0),
                'succeeded' => (int) ($row->succeeded ?? 0), 'skipped' => (int) ($row->skipped ?? 0),
                'last_error' => is_string($lastError) ? $lastError : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input, bool $creating): array
    {
        $errors = [];
        $out = [];
        if ($creating || array_key_exists('name', $input)) {
            $name = trim((string) ($input['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 160) {
                $errors['name'][] = (string) trans('validation.required', ['attribute' => 'name']);
            }
            $out['name'] = $name;
        }
        if (array_key_exists('owner_user_id', $input)) {
            $owner = $input['owner_user_id'];
            if ($owner !== null && (! is_string($owner) || preg_match('/^[0-9a-f-]{36}$/i', $owner) !== 1 || ! DB::table('users')->where('id', $owner)->exists())) {
                $errors['owner_user_id'][] = (string) trans('workflow.automation.invalid_owner');
            }
            $out['owner_user_id'] = $owner;
        }
        $event = (string) ($input['trigger_event'] ?? '');
        if (! $this->catalog->isTrigger($event)) {
            $errors['trigger_event'][] = (string) trans('workflow.automation.unknown_event');
        }
        $out['trigger_event'] = $event;

        if (array_key_exists('conditions', $input)) {
            $conditions = $input['conditions'];
            if ($conditions !== null && ! is_array($conditions)) {
                $errors['conditions'][] = (string) trans('workflow.automation.invalid_condition');
            } else {
                foreach (RuleConditions::errors($conditions) as $path) {
                    $errors[$path][] = (string) trans('workflow.automation.invalid_condition');
                }
                $out['conditions'] = $this->normalizeConditions($conditions);
            }
        }

        if ($creating || array_key_exists('actions', $input)) {
            $actions = $input['actions'] ?? null;
            if (! is_array($actions) || ! array_is_list($actions) || $actions === [] || count($actions) > self::MAX_ACTIONS) {
                $errors['actions'][] = (string) trans('workflow.automation.actions_required');
            } else {
                $clean = [];
                foreach ($actions as $i => $action) {
                    [$normalized, $actionErrors] = $this->validateAction(is_array($action) ? $action : [], "actions.{$i}");
                    $errors = array_merge_recursive($errors, $actionErrors);
                    $clean[] = $normalized;
                }
                $out['actions'] = $clean;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * @param  array<mixed>|null  $conditions
     * @return array<string, mixed>
     */
    private function normalizeConditions(?array $conditions): array
    {
        $rules = [];
        foreach ((array) ($conditions['rules'] ?? []) as $rule) {
            if (is_array($rule)) {
                $rules[] = array_filter(['field' => $rule['field'] ?? null, 'op' => $rule['op'] ?? null, 'value' => $rule['value'] ?? null], fn ($v, $k) => $k !== 'value' || array_key_exists('value', $rule), ARRAY_FILTER_USE_BOTH);
            }
        }

        return ['match' => ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all', 'rules' => $rules];
    }

    /**
     * WFL-6 · Las acciones de los nodos del constructor se validan igual que las de una regla.
     *
     * @param  array<mixed>  $a
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public function validateActionConfig(array $a, string $path): array
    {
        return $this->validateAction($a, $path);
    }

    /**
     * @param  array<mixed>  $a
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    private function validateAction(array $a, string $path): array
    {
        $type = $a['type'] ?? null;
        $invalid = (string) trans('workflow.automation.invalid_action');
        $errors = [];
        $text = fn (string $key, int $max, bool $required) => (! $required && ! array_key_exists($key, $a)) || (is_string($a[$key] ?? null) && trim((string) $a[$key]) !== '' && mb_strlen((string) $a[$key]) <= $max) || (! $required && ($a[$key] ?? null) === null);
        $field = fn (string $key, bool $required) => (! $required && ($a[$key] ?? null) === null) || (is_string($a[$key] ?? null) && preg_match(RuleConditions::FIELD_PATTERN, (string) $a[$key]) === 1);
        $uuid = fn (mixed $v) => is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
        $uuids = fn (string $key) => ! array_key_exists($key, $a) || $a[$key] === null || (is_array($a[$key]) && array_is_list($a[$key]) && count($a[$key]) <= 50 && array_filter($a[$key], fn ($v) => ! $uuid($v)) === []);

        switch ($type) {
            case 'create_task':
                foreach ([['title', 200, true], ['description', 5000, false]] as [$k, $max, $req]) {
                    if (! $text($k, $max, $req)) {
                        $errors["{$path}.{$k}"][] = $invalid;
                    }
                }
                foreach (['assignee_user_id', 'assignee_role_id'] as $k) {
                    if (($a[$k] ?? null) !== null && ! $uuid($a[$k])) {
                        $errors["{$path}.{$k}"][] = $invalid;
                    }
                }
                if (! $field('assignee_field', false)) {
                    $errors["{$path}.assignee_field"][] = $invalid;
                }
                if (($a['assignee_user_id'] ?? null) === null && ($a['assignee_role_id'] ?? null) === null && ($a['assignee_field'] ?? null) === null) {
                    $errors["{$path}.assignee_user_id"][] = (string) trans('workflow.task_assignee_required');
                }
                if (array_key_exists('due_in_days', $a) && $a['due_in_days'] !== null && (! is_int($a['due_in_days']) || $a['due_in_days'] < 0 || $a['due_in_days'] > 365)) {
                    $errors["{$path}.due_in_days"][] = $invalid;
                }
                if (array_key_exists('priority', $a) && ! in_array($a['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
                    $errors["{$path}.priority"][] = $invalid;
                }
                $keys = ['title', 'description', 'assignee_user_id', 'assignee_role_id', 'assignee_field', 'due_in_days', 'priority'];
                break;
            case 'notify':
                foreach ([['title', 120, true], ['message', 1000, true]] as [$k, $max, $req]) {
                    if (! $text($k, $max, $req)) {
                        $errors["{$path}.{$k}"][] = $invalid;
                    }
                }
                foreach (['user_ids', 'role_ids'] as $k) {
                    if (! $uuids($k)) {
                        $errors["{$path}.{$k}"][] = $invalid;
                    }
                }
                if (! $field('user_field', false)) {
                    $errors["{$path}.user_field"][] = $invalid;
                }
                if (empty($a['user_ids']) && empty($a['role_ids']) && ($a['user_field'] ?? null) === null) {
                    $errors["{$path}.user_ids"][] = (string) trans('workflow.automation.no_recipients');
                }
                $keys = ['title', 'message', 'user_ids', 'role_ids', 'user_field'];
                break;
            case 'start_approval':
                if (! in_array($a['subject_type'] ?? null, $this->catalog->subjectTypes(), true)) {
                    $errors["{$path}.subject_type"][] = (string) trans('workflow.automation.invalid_subject_type');
                }
                foreach (['subject_id_field', 'requester_field'] as $k) {
                    if (! $field($k, true)) {
                        $errors["{$path}.{$k}"][] = $invalid;
                    }
                }
                $keys = ['subject_type', 'subject_id_field', 'requester_field'];
                break;
            default:
                return [[], ["{$path}.type" => [(string) trans('workflow.automation.unknown_action')]]];
        }

        $normalized = ['type' => $type];
        foreach ($keys as $k) {
            if (array_key_exists($k, $a) && $a[$k] !== null && $a[$k] !== []) {
                $normalized[$k] = $a[$k];
            }
        }

        return [$normalized, $errors];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AutomationRule $rule): array
    {
        return ['name' => $rule->name, 'trigger_event' => $rule->trigger_event, 'conditions' => $rule->conditions, 'actions' => $rule->actions, 'status' => $rule->status];
    }
}
