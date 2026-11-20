<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\Automation\RuleConditions;
use App\Modules\Workflow\Domain\Flow\FlowGraph;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Modules\Workflow\Infrastructure\Models\AutomationRun;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\ProcessedEventTracker;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * WFL-5 · Ejecuta las reglas activas que escuchan un evento.
 *
 *  - Idempotente por regla y evento (ProcessedEventTracker): entregar dos veces el mismo evento no repite efectos.
 *  - Cada acción corre en su propia transacción; si falla, la ejecución queda «failed» con el error, las
 *    acciones siguientes no se ejecutan y se reintenta según automatizaciones.reintentos (RN-WFL-10).
 *  - Protección contra bucles: superar automatizaciones.max_ejecuciones_hora pausa la regla y avisa al
 *    administrador (RN-WFL-11).
 *  - Nunca lanza: un error de una regla no impide que corran las demás ni que el evento se publique.
 */
final class AutomationRunner
{
    public function __construct(
        private readonly AutomationCatalog $catalog,
        private readonly AutomationRuleService $rules,
        private readonly TaskService $tasks,
        private readonly ApprovalEngine $approvals,
        private readonly Notifier $notifier,
        private readonly ParameterResolver $parameters,
        private readonly ProcessedEventTracker $tracker,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return int reglas ejecutadas (no cuenta las ya procesadas para este evento)
     */
    public function dispatch(string $tenantId, string $eventId, string $eventType, array $payload): int
    {
        if (! $this->catalog->isTrigger($eventType)) {
            return 0;
        }
        $count = 0;
        $rules = AutomationRule::query()->where('status', 'active')->where('trigger_event', $eventType)->orderBy('created_at')->get();
        foreach ($rules as $rule) {
            $ran = $this->tracker->executeIdempotently($tenantId, 'workflow.automation:'.$rule->id, $eventId, fn () => $this->run($rule, $eventId, $eventType, $payload));
            $count += $ran ? 1 : 0;
        }

        return $count;
    }

    /**
     * @param  array<mixed>  $payload
     */
    public function run(AutomationRule $rule, ?string $eventId, string $eventType, array $payload): AutomationRun
    {
        $payload = $this->catalog->redact($payload);
        $base = ['event_type' => $eventType, 'payload' => $payload, 'attempts' => 1, 'actions' => [],
            // WFL-7 · Qué evaluó cada condición, para el registro de la ejecución.
            'conditions' => RuleConditions::explain($rule->conditions, $payload)];
        $flow = $rule->workflow_definition_id === null ? null : $this->publishedGraph($rule->workflow_definition_id);
        if ($flow !== null) {
            // WFL-6 · Un flujo del constructor sigue la versión publicada con la que empezó (RN-WFL-08).
            $base['flow_version_id'] = $flow['version_id'];
            $base['path'] = [];
        }

        $max = max(1, (int) ($this->parameters->value('automatizaciones.max_ejecuciones_hora') ?? 100));
        $recent = AutomationRun::query()->where('automation_rule_id', $rule->id)->where('started_at', '>=', now()->subHour())->whereNotIn('status', ['skipped', 'cancelled'])->count();
        if ($recent >= $max) {
            $this->rules->autoPause($rule, $max);
            $this->notifyAdmins($rule);

            return $this->record($rule, $eventId, 'cancelled', $base, (string) trans('workflow.automation.loop_paused', ['max' => $max]));
        }

        if (! RuleConditions::matches($rule->conditions, $payload)) {
            return $this->record($rule, $eventId, 'skipped', $base);
        }

        $run = $this->record($rule, $eventId, 'running', $base, null, false);
        DB::table('automation_rules')->where('id', $rule->id)->update(['last_run_at' => now()]);

        if ($flow !== null) {
            $start = FlowGraph::start($flow['graph']);

            return $this->track($rule, $this->executeGraph($run, $flow['graph'], $start === null ? null : (string) $start['id']));
        }

        return $this->track($rule, $this->execute($run, $rule, 0));
    }

    /**
     * Reintento (manual o programado): retoma desde la acción que falló, sin repetir las que ya se hicieron.
     */
    public function retry(AutomationRun $run): AutomationRun
    {
        if ($run->status !== 'failed') {
            throw new BusinessRuleViolatedException('RN-WFL-10', (string) trans('workflow.automation.not_retryable'));
        }
        /** @var AutomationRule|null $rule */
        $rule = AutomationRule::withTrashed()->find($run->automation_rule_id);
        if ($rule === null || $rule->trashed()) {
            throw new BusinessRuleViolatedException('RN-WFL-10', (string) trans('workflow.automation.not_retryable'));
        }
        $log = $run->log ?? [];
        $from = 0;
        foreach ((array) ($log['actions'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['status'] ?? null) === 'failed') {
                $from = (int) ($entry['index'] ?? 0);
                break;
            }
        }
        $log['attempts'] = (int) ($log['attempts'] ?? 1) + 1;
        $run->forceFill(['status' => 'running', 'error' => null, 'finished_at' => null, 'log' => $log])->save();

        if (isset($log['flow_version_id'])) {
            $graph = $this->versionGraph((string) $log['flow_version_id']);
            if ($graph === null) {
                throw new BusinessRuleViolatedException('RN-WFL-10', (string) trans('workflow.automation.not_retryable'));
            }

            return $this->track($rule, $this->executeGraph($run, $graph, isset($log['cursor']) ? (string) $log['cursor'] : null));
        }

        return $this->track($rule, $this->execute($run, $rule, $from));
    }

    /**
     * WFL-6 · Retoma los flujos que esperaban en un nodo «espera» y cuyo plazo venció.
     */
    public function resumeDue(): int
    {
        $count = 0;
        $runs = AutomationRun::query()->where('status', 'waiting')
            ->whereRaw("(log->>'resume_at') IS NOT NULL AND (log->>'resume_at')::timestamptz <= ?::timestamptz", [now()->toIso8601String()])
            ->orderBy('started_at')->limit(100)->get();
        foreach ($runs as $run) {
            /** @var AutomationRule|null $rule */
            $rule = AutomationRule::query()->whereKey($run->automation_rule_id)->where('status', 'active')->first();
            $log = $run->log ?? [];
            $graph = isset($log['flow_version_id']) ? $this->versionGraph((string) $log['flow_version_id']) : null;
            if ($rule === null || $graph === null) {
                continue;
            }
            unset($log['resume_at']);
            $run->forceFill(['status' => 'running', 'log' => $log])->save();
            $this->track($rule, $this->executeGraph($run, $graph, isset($log['cursor']) ? (string) $log['cursor'] : null));
            $count++;
        }

        return $count;
    }

    public function cancel(AutomationRun $run): AutomationRun
    {
        if (! in_array($run->status, ['failed', 'waiting', 'queued'], true)) {
            throw new BusinessRuleViolatedException('RN-WFL-10', (string) trans('workflow.automation.not_cancellable'));
        }
        $log = $run->log ?? [];
        unset($log['next_retry_at']);
        $run->forceFill(['status' => 'cancelled', 'finished_at' => now(), 'log' => $log])->save();

        return $run;
    }

    /**
     * Reintentos programados de ejecuciones fallidas cuyo plazo venció.
     */
    public function retryDue(): int
    {
        $count = 0;
        $runs = AutomationRun::query()->where('status', 'failed')
            ->whereRaw("(log->>'next_retry_at') IS NOT NULL AND (log->>'next_retry_at')::timestamptz <= ?::timestamptz", [now()->toIso8601String()])
            ->orderBy('started_at')->limit(100)->get();
        foreach ($runs as $run) {
            $active = AutomationRule::query()->whereKey($run->automation_rule_id)->where('status', 'active')->exists();
            if (! $active) {
                continue;
            }
            $this->retry($run);
            $count++;
        }

        return $count;
    }

    /**
     * Vista previa sin efectos: si la regla se cumple con estos datos y qué haría cada acción.
     *
     * @param  array<mixed>  $payload
     * @return array{matches: bool, actions: list<array<string, mixed>>}
     */
    public function preview(AutomationRule $rule, array $payload): array
    {
        $payload = $this->catalog->redact($payload);
        $matches = RuleConditions::matches($rule->conditions, $payload);
        if ($rule->workflow_definition_id !== null) {
            $flow = $this->publishedGraph($rule->workflow_definition_id);

            return ['matches' => true, 'actions' => [], 'conditions' => [], 'path' => $flow === null ? [] : $this->simulate($flow['graph'], $payload)['path']];
        }
        $actions = [];
        foreach (array_values($rule->actions) as $i => $action) {
            $entry = ['index' => $i, 'type' => (string) ($action['type'] ?? '')];
            try {
                $entry['params'] = $this->params($action, $payload);
                $entry['status'] = 'ready';
            } catch (Throwable $e) {
                $entry['status'] = 'error';
                $entry['error'] = $e->getMessage();
            }
            $actions[] = $entry;
        }

        return ['matches' => $matches, 'actions' => $actions, 'conditions' => RuleConditions::explain($rule->conditions, $payload)];
    }

    /**
     * WFL-6 · Modo de prueba del constructor: el recorrido que seguiría un caso de ejemplo, sin efectos.
     * Las esperas se muestran pero no se aguardan.
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @param  array<mixed>  $payload
     * @return array{path: list<array<string, mixed>>, reached_end: bool}
     */
    public function simulate(array $graph, array $payload): array
    {
        $payload = $this->catalog->redact($payload);
        $path = [];
        $start = FlowGraph::start($graph);
        $id = $start === null ? null : (string) $start['id'];
        $reachedEnd = false;
        for ($guard = 0; $id !== null && $guard < FlowGraph::MAX_NODES; $guard++) {
            $node = FlowGraph::node($graph, $id);
            if ($node === null) {
                break;
            }
            $entry = ['node' => $id, 'kind' => $node['kind'], 'label' => $node['label']];
            $branch = null;
            $stop = false;
            switch ($node['kind']) {
                case 'condition':
                    $branch = RuleConditions::matches((array) $node['config'], $payload) ? 'yes' : 'no';
                    $entry += ['status' => 'evaluated', 'branch' => $branch, 'conditions' => RuleConditions::explain((array) $node['config'], $payload)];
                    break;
                case 'approval':
                case 'action':
                case 'notification':
                    try {
                        $entry += ['status' => 'ready', 'params' => $this->params($this->nodeAction($node), $payload)];
                    } catch (Throwable $e) {
                        $entry += ['status' => 'error', 'error' => $e->getMessage()];
                        $stop = true;
                    }
                    break;
                case 'wait':
                    $entry += ['status' => 'would_wait', 'days' => (int) (((array) $node['config'])['days'] ?? 0)];
                    break;
                case 'end':
                    $entry += ['status' => 'end'];
                    $reachedEnd = true;
                    $stop = true;
                    break;
                default:
                    $entry += ['status' => 'passed'];
            }
            $path[] = $entry;
            if ($stop) {
                break;
            }
            $id = FlowGraph::next($graph, $id, $branch);
        }

        return ['path' => $path, 'reached_end' => $reachedEnd];
    }

    /**
     * WFL-6 · Recorre el grafo publicado desde $from. Cada nodo de acción corre en su propia transacción;
     * si falla, la ejecución queda «failed» en ese nodo y se reintenta desde ahí. Un nodo «espera» deja la
     * ejecución «waiting» hasta que venza su plazo (workflow:retry-automations la retoma).
     *
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     */
    private function executeGraph(AutomationRun $run, array $graph, ?string $from): AutomationRun
    {
        $log = $run->log ?? [];
        /** @var array<mixed> $payload */
        $payload = is_array($log['payload'] ?? null) ? $log['payload'] : [];
        $path = array_values((array) ($log['path'] ?? []));
        unset($log['next_retry_at']);
        $error = $from === null ? (string) trans('workflow.flows.broken_graph') : null;
        $id = $from;
        for ($guard = 0; $error === null && $guard < FlowGraph::MAX_NODES * 2; $guard++) {
            $node = FlowGraph::node($graph, $id);
            if ($node === null) {
                $error = (string) trans('workflow.flows.broken_graph');
                break;
            }
            $entry = ['node' => $id, 'kind' => $node['kind'], 'label' => $node['label'], 'at' => now()->toIso8601String()];
            $branch = null;
            if ($node['kind'] === 'condition') {
                $branch = RuleConditions::matches((array) $node['config'], $payload) ? 'yes' : 'no';
                $entry += ['status' => 'evaluated', 'branch' => $branch, 'conditions' => RuleConditions::explain((array) $node['config'], $payload)];
            } elseif (in_array($node['kind'], ['approval', 'action', 'notification'], true)) {
                try {
                    $result = DB::transaction(fn () => $this->perform($this->nodeAction($node), $payload, $run));
                    $entry += ['status' => 'succeeded', 'result' => $result];
                } catch (Throwable $e) {
                    // Al reintentar se vuelve a este nodo; el intento fallido queda en el recorrido.
                    $error = $this->message($e);
                    $path[] = $entry + ['status' => 'failed', 'error' => $error];
                    $log['cursor'] = $id;
                    break;
                }
            } elseif ($node['kind'] === 'wait') {
                $days = max(1, (int) (((array) $node['config'])['days'] ?? 1));
                $path[] = $entry + ['status' => 'waiting', 'days' => $days];
                $log['cursor'] = FlowGraph::next($graph, $id);
                $log['resume_at'] = now()->addDays($days)->toIso8601String();
                $log['path'] = $path;
                $run->forceFill(['status' => 'waiting', 'error' => null, 'log' => $log])->save();

                return $run;
            } elseif ($node['kind'] === 'end') {
                $path[] = $entry + ['status' => 'end'];
                $id = null;
                break;
            } else {
                $entry += ['status' => 'passed'];
            }
            $path[] = $entry;
            $id = FlowGraph::next($graph, $id, $branch);
            if ($id === null) {
                $error = (string) trans('workflow.flows.broken_graph');
            }
        }

        $log['path'] = $path;
        if ($error === null) {
            unset($log['cursor']);
        } else {
            $retries = max(0, (int) ($this->parameters->value('automatizaciones.reintentos') ?? 3));
            $attempts = (int) ($log['attempts'] ?? 1);
            if ($attempts <= $retries) {
                $log['next_retry_at'] = now()->addMinutes(5 * (2 ** ($attempts - 1)))->toIso8601String();
            }
        }
        $run->forceFill(['status' => $error === null ? 'succeeded' : 'failed', 'error' => $error, 'finished_at' => now(), 'log' => $log])->save();

        return $run;
    }

    /**
     * Configuración de un nodo como una acción de la lista cerrada.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function nodeAction(array $node): array
    {
        $type = match ($node['kind']) {
            'approval' => 'start_approval',
            'action' => 'create_task',
            default => 'notify',
        };

        return ['type' => $type] + (array) $node['config'];
    }

    /**
     * Grafo de la versión publicada vigente de un flujo del constructor.
     *
     * @return array{version_id: string, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}}|null
     */
    private function publishedGraph(string $definitionId): ?array
    {
        $versionId = DB::table('workflow_definitions')->where('id', $definitionId)->value('current_version_id');
        if (! is_string($versionId)) {
            return null;
        }
        $graph = $this->versionGraph($versionId);

        return $graph === null ? null : ['version_id' => $versionId, 'graph' => $graph];
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}|null
     */
    private function versionGraph(string $versionId): ?array
    {
        $raw = DB::table('workflow_definition_versions')->where('id', $versionId)->whereNotNull('published_at')->value('graph');

        return FlowGraph::normalize(is_string($raw) ? json_decode($raw, true) : null);
    }

    /**
     * WFL-7 · Una regla con varias fallas seguidas (automatizaciones.errores_seguidos) se marca «con errores»
     * y se avisa una sola vez a su responsable; una ejecución correcta quita la marca.
     */
    private function track(AutomationRule $rule, AutomationRun $run): AutomationRun
    {
        try {
            if ($run->status === 'succeeded') {
                DB::table('automation_rules')->where('id', $rule->id)->whereNotNull('failing_since')->update(['failing_since' => null]);

                return $run;
            }
            if ($run->status !== 'failed') {
                return $run;
            }
            $limit = max(1, (int) ($this->parameters->value('automatizaciones.errores_seguidos') ?? 3));
            $recent = AutomationRun::query()->where('automation_rule_id', $rule->id)->whereIn('status', ['succeeded', 'failed'])
                ->orderByDesc('started_at')->limit($limit)->pluck('status')->all();
            $alreadyFailing = DB::table('automation_rules')->where('id', $rule->id)->whereNotNull('failing_since')->exists();
            if (count($recent) < $limit || in_array('succeeded', $recent, true) || $alreadyFailing) {
                return $run;
            }
            DB::table('automation_rules')->where('id', $rule->id)->update(['failing_since' => now()]);
            $owner = DB::table('automation_rules')->where('id', $rule->id)->value('owner_user_id');
            $users = is_string($owner) ? [$owner] : $this->adminUsers();
            $this->notifier->notify('automation.failing', $users, ['rule' => $rule->name, 'count' => (string) $limit, 'error' => (string) $run->error], 'automation_rule', $rule->id);
        } catch (Throwable $e) {
            // La marca y el aviso son mejor esfuerzo: la falla ya quedó en el registro de la ejecución.
            report($e);
        }

        return $run;
    }

    /**
     * @return list<string>
     */
    private function adminUsers(): array
    {
        $users = [];
        foreach (DB::table('roles')->where('system_role_code', 'admin_empresa')->whereNull('deleted_at')->pluck('id') as $roleId) {
            $users = [...$users, ...$this->notifier->usersWithRole((string) $roleId)];
        }

        return array_values(array_unique(array_map('strval', $users)));
    }

    private function execute(AutomationRun $run, AutomationRule $rule, int $from): AutomationRun
    {
        $log = $run->log ?? [];
        /** @var array<mixed> $payload */
        $payload = is_array($log['payload'] ?? null) ? $log['payload'] : [];
        $results = array_values(array_filter((array) ($log['actions'] ?? []), fn ($e) => is_array($e) && (int) ($e['index'] ?? -1) < $from));
        $error = null;
        foreach (array_values($rule->actions) as $i => $action) {
            if ($i < $from) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            if ($error !== null) {
                $results[] = ['index' => $i, 'type' => $type, 'status' => 'not_run'];

                continue;
            }
            try {
                $result = DB::transaction(fn () => $this->perform($action, $payload, $run));
                $results[] = ['index' => $i, 'type' => $type, 'status' => 'succeeded', 'result' => $result];
            } catch (Throwable $e) {
                $error = $this->message($e);
                $results[] = ['index' => $i, 'type' => $type, 'status' => 'failed', 'error' => $error];
            }
        }

        $log['actions'] = $results;
        unset($log['next_retry_at']);
        if ($error !== null) {
            $retries = max(0, (int) ($this->parameters->value('automatizaciones.reintentos') ?? 3));
            $attempts = (int) ($log['attempts'] ?? 1);
            if ($attempts <= $retries) {
                $log['next_retry_at'] = now()->addMinutes(5 * (2 ** ($attempts - 1)))->toIso8601String();
            }
        }
        $run->forceFill(['status' => $error === null ? 'succeeded' : 'failed', 'error' => $error, 'finished_at' => now(), 'log' => $log])->save();

        return $run;
    }

    /**
     * Las únicas tres acciones posibles. Cualquier otro tipo es un error (no se ejecuta nada).
     *
     * @param  array<mixed>  $action
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    private function perform(array $action, array $payload, AutomationRun $run): array
    {
        $params = $this->params($action, $payload);

        return match ($action['type'] ?? null) {
            'create_task' => ['task_id' => $this->tasks->create($params + ['related_type' => 'automation_run', 'related_id' => $run->id], null)->id],
            'notify' => ['notified' => $this->notify($params, $run)],
            'start_approval' => ['approval_request_id' => $this->approvals->start((string) $params['subject_type'], (string) $params['subject_id'], (string) $params['requester_user_id'], ['automation_run_id' => $run->id])->id],
            default => throw new RuntimeException((string) trans('workflow.automation.unknown_action')),
        };
    }

    /**
     * Parámetros concretos de la acción con los datos del evento.
     *
     * @param  array<mixed>  $action
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    private function params(array $action, array $payload): array
    {
        $fill = fn (?string $text) => $text === null ? null : $this->template($text, $payload);

        switch ($action['type'] ?? null) {
            case 'create_task':
                $assignee = isset($action['assignee_field']) ? $this->required($payload, (string) $action['assignee_field']) : ($action['assignee_user_id'] ?? null);

                return array_filter([
                    'title' => mb_substr((string) $fill((string) ($action['title'] ?? '')), 0, 200),
                    'description' => $fill(isset($action['description']) ? (string) $action['description'] : null),
                    'assignee_user_id' => $assignee,
                    'assignee_role_id' => $assignee === null ? ($action['assignee_role_id'] ?? null) : null,
                    'due_on' => isset($action['due_in_days']) ? now()->addDays((int) $action['due_in_days'])->toDateString() : null,
                    'priority' => $action['priority'] ?? 'normal',
                ], fn ($v) => $v !== null);
            case 'notify':
                $users = array_map('strval', (array) ($action['user_ids'] ?? []));
                if (isset($action['user_field'])) {
                    $users[] = $this->required($payload, (string) $action['user_field']);
                }

                return [
                    'title' => mb_substr((string) $fill((string) ($action['title'] ?? '')), 0, 120),
                    'message' => mb_substr((string) $fill((string) ($action['message'] ?? '')), 0, 1000),
                    'user_ids' => array_values(array_unique($users)),
                    'role_ids' => array_values(array_map('strval', (array) ($action['role_ids'] ?? []))),
                ];
            case 'start_approval':
                return [
                    'subject_type' => (string) ($action['subject_type'] ?? ''),
                    'subject_id' => $this->required($payload, (string) ($action['subject_id_field'] ?? '')),
                    'requester_user_id' => $this->required($payload, (string) ($action['requester_field'] ?? '')),
                ];
            default:
                throw new RuntimeException((string) trans('workflow.automation.unknown_action'));
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function notify(array $params, AutomationRun $run): int
    {
        $users = (array) $params['user_ids'];
        foreach ((array) $params['role_ids'] as $roleId) {
            $users = [...$users, ...$this->notifier->usersWithRole((string) $roleId)];
        }

        return $this->notifier->notify('automation.notice', array_values(array_unique(array_map('strval', $users))), [
            'title' => (string) $params['title'], 'message' => (string) $params['message'],
        ], 'automation_run', $run->id);
    }

    private function notifyAdmins(AutomationRule $rule): void
    {
        try {
            $this->notifier->notify('automation.paused', $this->adminUsers(), ['rule' => $rule->name], 'automation_rule', $rule->id);
        } catch (Throwable) {
            // El aviso es mejor esfuerzo: la pausa ya quedó registrada en la auditoría.
        }
    }

    /**
     * Reemplaza {{campo}} por el valor del evento. Solo se insertan valores simples: nada se evalúa.
     *
     * @param  array<mixed>  $payload
     */
    private function template(string $text, array $payload): string
    {
        return (string) preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}\}/', function (array $m) use ($payload): string {
            $value = RuleConditions::get($payload, $m[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $text);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function required(array $payload, string $field): string
    {
        $value = RuleConditions::get($payload, $field);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException((string) trans('workflow.automation.missing_field', ['field' => $field]));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function record(AutomationRule $rule, ?string $eventId, string $status, array $base, ?string $error = null, bool $finished = true): AutomationRun
    {
        return AutomationRun::create([
            'automation_rule_id' => $rule->id,
            'trigger_event_id' => $eventId,
            'status' => $status,
            'started_at' => now(),
            'finished_at' => $finished ? now() : null,
            'log' => $base,
            'error' => $error,
        ]);
    }

    private function message(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();

            return mb_substr(is_string($first) ? $first : $e->getMessage(), 0, 500);
        }
        if ($e instanceof QueryException) {
            // El detalle técnico va a los logs, no al centro de automatizaciones.
            report($e);

            return (string) trans('workflow.automation.invalid_action');
        }

        return mb_substr($e->getMessage(), 0, 500);
    }
}
