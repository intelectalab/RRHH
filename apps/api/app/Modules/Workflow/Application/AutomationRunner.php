<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\Automation\RuleConditions;
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
        $base = ['event_type' => $eventType, 'payload' => $payload, 'attempts' => 1, 'actions' => []];

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

        return $this->execute($run, $rule, 0);
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

        return $this->execute($run, $rule, $from);
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

        return ['matches' => $matches, 'actions' => $actions];
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
            $roles = DB::table('roles')->where('system_role_code', 'admin_empresa')->whereNull('deleted_at')->pluck('id');
            $users = [];
            foreach ($roles as $roleId) {
                $users = [...$users, ...$this->notifier->usersWithRole((string) $roleId)];
            }
            $this->notifier->notify('automation.paused', array_values(array_unique($users)), ['rule' => $rule->name], 'automation_rule', $rule->id);
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
