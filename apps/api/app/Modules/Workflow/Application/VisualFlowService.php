<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\Automation\RuleConditions;
use App\Modules\Workflow\Domain\Flow\FlowGraph;
use App\Modules\Workflow\Domain\VisualFlowStateMachine;
use App\Modules\Workflow\Infrastructure\Models\ApprovalFlowVersion;
use App\Modules\Workflow\Infrastructure\Models\AutomationRule;
use App\Modules\Workflow\Infrastructure\Models\VisualFlow;
use App\Modules\Workflow\Infrastructure\Models\WorkflowStep;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WFL-6 · Constructor visual de flujos. El lienzo guarda borradores sobre una versión sin publicar; publicar
 * exige un diseño válido (FlowGraph::issues), materializa nodos y conexiones en la versión y deja una regla
 * de automatización asociada (automation_rules.workflow_definition_id) que escucha el disparador del nodo
 * de inicio. Así el mismo motor, el centro de automatizaciones (WFL-7) y el catálogo de eventos y acciones
 * de todos los módulos sirven para cualquier flujo.
 */
final class VisualFlowService
{
    public function __construct(
        private readonly VisualFlowStateMachine $machine,
        private readonly AutomationCatalog $catalog,
        private readonly AutomationRuleService $rules,
        private readonly AutomationRunner $runner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(VisualFlow $flow): array
    {
        $latest = $this->latestVersion($flow);
        $published = $flow->current_version_id === null ? null : ApprovalFlowVersion::query()->find($flow->current_version_id);
        $graph = FlowGraph::normalize($latest?->graph) ?? ['nodes' => [], 'edges' => []];
        $rule = AutomationRule::query()->where('workflow_definition_id', $flow->id)->first(['id']);

        return [
            'id' => $flow->id,
            'name' => $flow->name,
            'description' => $flow->description,
            'status' => match ($flow->status) {
                'active' => 'active', 'inactive' => 'paused', 'archived' => 'retired', default => 'draft'
            },
            'graph' => $graph,
            'issues' => $this->issues($graph),
            'version' => $published instanceof ApprovalFlowVersion ? $published->version : 0,
            'has_draft' => $latest !== null && $latest->published_at === null && $published !== null,
            'published_at' => $published instanceof ApprovalFlowVersion ? $published->published_at?->toIso8601String() : null,
            'workflow_id' => $rule?->id,
            'allowed_actions' => $flow->status === 'archived' ? [] : $this->machine->allowedActions((string) $flow->status),
            'created_at' => $flow->created_at?->toIso8601String(),
            'updated_at' => $flow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): VisualFlow
    {
        $name = $this->name($input, true);
        $graph = array_key_exists('graph', $input) ? $this->graph($input['graph']) : $this->skeleton();

        return DB::transaction(function () use ($input, $name, $graph): VisualFlow {
            $flow = VisualFlow::create([
                'code' => 'vflow_'.Str::slug($name, '_').'_'.Str::lower(Str::random(4)),
                'name' => $name,
                'description' => $this->description($input),
                'status' => 'draft',
            ]);
            $flow->versions()->create(['version' => 1, 'graph' => $graph]);
            $this->audit->record('visual_flow.created', 'visual_flow', $flow->id, null, ['name' => $name]);

            return $flow;
        });
    }

    /**
     * Guarda el borrador. Un diseño a medias se guarda igual: la validación completa es para publicar.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(VisualFlow $flow, array $input): VisualFlow
    {
        if ($flow->status === 'archived') {
            throw new AyniException('invalid_transition', 409, (string) trans('workflow.flows.invalid_graph'));
        }
        $name = array_key_exists('name', $input) ? $this->name($input, true) : null;
        $graph = array_key_exists('graph', $input) ? $this->graph($input['graph']) : null;

        return DB::transaction(function () use ($flow, $input, $name, $graph): VisualFlow {
            $before = ['name' => $flow->name, 'description' => $flow->description];
            if ($name !== null) {
                $flow->name = $name;
            }
            if (array_key_exists('description', $input)) {
                $flow->description = $this->description($input);
            }
            if ($flow->isDirty()) {
                $flow->save();
                AutomationRule::query()->where('workflow_definition_id', $flow->id)->update(['name' => $flow->name]);
            }
            if ($graph !== null) {
                $this->draftVersion($flow)->forceFill(['graph' => $graph])->save();
            }
            $this->audit->record('visual_flow.updated', 'visual_flow', $flow->id, $before, ['name' => $flow->name, 'description' => $flow->description, 'graph_changed' => $graph !== null]);

            return $flow;
        });
    }

    /**
     * Modo de prueba: el recorrido que seguiría un caso de ejemplo sobre el borrador (o el diseño enviado).
     *
     * @param  array<mixed>  $payload
     * @return array<string, mixed>
     */
    public function test(VisualFlow $flow, array $payload, mixed $graph = null): array
    {
        $graph = $graph === null ? (FlowGraph::normalize($this->latestVersion($flow)?->graph) ?? $this->skeleton()) : $this->graph($graph);

        return $this->runner->simulate($graph, $payload) + ['issues' => $this->issues($graph)];
    }

    public function publish(VisualFlow $flow, ?string $userId): VisualFlow
    {
        $next = $this->machine->getNextState((string) $flow->status, 'publish');
        $version = $this->latestVersion($flow);
        if ($version === null || $version->published_at !== null) {
            throw new AyniException('nothing_to_publish', 409, (string) trans('workflow.flows.nothing_to_publish'));
        }
        $graph = FlowGraph::normalize($version->graph) ?? $this->skeleton();
        $issues = $this->issues($graph);
        if ($issues !== []) {
            throw ValidationException::withMessages(['graph' => array_values(array_unique(array_map(fn ($i) => (string) trans('workflow.flows.issues.'.$i['code']), $issues)))]);
        }
        $start = FlowGraph::start($graph);
        $trigger = (string) ((array) ($start['config'] ?? []))['trigger_event'];

        return DB::transaction(function () use ($flow, $version, $graph, $next, $userId, $trigger): VisualFlow {
            $version->forceFill(['published_at' => now(), 'published_by_user_id' => $userId])->save();
            $stepIds = [];
            foreach ($graph['nodes'] as $i => $node) {
                $stepIds[$node['id']] = WorkflowStep::create([
                    'workflow_definition_version_id' => $version->id,
                    'key' => $node['id'],
                    'name' => $node['label'] !== '' ? $node['label'] : $node['kind'],
                    'kind' => $node['kind'],
                    'sort_order' => $i + 1,
                    'condition' => $node['kind'] === 'condition' ? $node['config'] : null,
                    'action_config' => $node['kind'] === 'condition' ? null : $node['config'],
                ])->id;
            }
            foreach ($graph['edges'] as $i => $edge) {
                DB::table('workflow_transitions')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $flow->tenant_id,
                    'workflow_definition_version_id' => $version->id,
                    'from_step_id' => $stepIds[$edge['from']],
                    'to_step_id' => $stepIds[$edge['to']],
                    'condition' => $edge['branch'] === null ? null : json_encode(['branch' => $edge['branch']]),
                    'label' => $edge['branch'],
                    'sort_order' => $i + 1,
                ]);
            }
            $flow->forceFill(['status' => $next, 'current_version_id' => $version->id])->save();

            $rule = AutomationRule::query()->where('workflow_definition_id', $flow->id)->first();
            $ruleStatus = $next === 'active' ? 'active' : 'paused';
            if ($rule === null) {
                AutomationRule::create([
                    'name' => $flow->name, 'trigger_event' => $trigger, 'conditions' => null, 'actions' => [],
                    'status' => $ruleStatus, 'workflow_definition_id' => $flow->id, 'owner_user_id' => $userId,
                ]);
            } else {
                $rule->forceFill(['name' => $flow->name, 'trigger_event' => $trigger, 'status' => $ruleStatus])->save();
            }
            $this->audit->record('visual_flow.published', 'visual_flow', $flow->id, null, ['version' => $version->version, 'trigger_event' => $trigger]);

            return $flow;
        });
    }

    public function transition(VisualFlow $flow, string $action): VisualFlow
    {
        $next = $this->machine->getNextState((string) $flow->status, $action);
        if ($action === 'activate' && $flow->current_version_id === null) {
            throw new AyniException('nothing_to_publish', 409, (string) trans('workflow.flows.not_publishable'));
        }

        return DB::transaction(function () use ($flow, $action, $next): VisualFlow {
            $before = ['status' => $flow->status];
            $flow->forceFill(['status' => $next])->save();
            $rule = AutomationRule::query()->where('workflow_definition_id', $flow->id)->first();
            if ($next === 'archived') {
                $rule?->forceFill(['status' => 'paused'])->save();
                $rule?->delete();
                $flow->delete();
            } else {
                $rule?->forceFill(['status' => $next === 'active' ? 'active' : 'paused'] + ($next === 'active' ? ['failing_since' => null] : []))->save();
            }
            $this->audit->record("visual_flow.{$action}", 'visual_flow', $flow->id, $before, ['status' => $next]);

            return $flow;
        });
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $graph
     * @return list<array{code: string, node: string|null, fields?: list<string>, message: string}>
     */
    public function issues(array $graph): array
    {
        return array_map(
            fn (array $i) => $i + ['message' => (string) trans('workflow.flows.issues.'.$i['code'])],
            FlowGraph::issues($graph, fn (array $node) => $this->configErrors($node)),
        );
    }

    /**
     * Campos con error en la configuración de un nodo (las acciones son las mismas de una regla, RN-WFL-09).
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function configErrors(array $node): array
    {
        $config = (array) ($node['config'] ?? []);

        return match ($node['kind']) {
            'start' => $this->catalog->isTrigger((string) ($config['trigger_event'] ?? '')) ? [] : ['trigger_event'],
            'condition' => (array) ($config['rules'] ?? []) === [] ? ['rules'] : array_values(array_unique(RuleConditions::errors($config))),
            'approval' => $this->actionErrors('start_approval', $config),
            'action' => $this->actionErrors('create_task', $config),
            'notification' => $this->actionErrors('notify', $config),
            'wait' => is_int($config['days'] ?? null) && $config['days'] >= 1 && $config['days'] <= 90 ? [] : ['days'],
            default => [],
        };
    }

    /**
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private function actionErrors(string $type, array $config): array
    {
        [, $errors] = $this->rules->validateActionConfig(['type' => $type] + $config, 'config');

        return array_map(fn (string $k) => (string) preg_replace('/^config\./', '', $k), array_keys($errors));
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function graph(mixed $graph): array
    {
        $normalized = FlowGraph::normalize($graph);
        if ($normalized === null) {
            throw ValidationException::withMessages(['graph' => [(string) trans('workflow.flows.invalid_graph')]]);
        }

        return $normalized;
    }

    /**
     * Lienzo nuevo: inicio y fin listos para conectar.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function skeleton(): array
    {
        return ['nodes' => [
            ['id' => 'start', 'kind' => 'start', 'label' => '', 'x' => 40, 'y' => 40, 'config' => []],
            ['id' => 'end', 'kind' => 'end', 'label' => '', 'x' => 40, 'y' => 320, 'config' => []],
        ], 'edges' => []];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function name(array $input, bool $required): string
    {
        $name = trim((string) (is_scalar($input['name'] ?? null) ? $input['name'] : ''));
        if (($required && $name === '') || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => [(string) trans('validation.required', ['attribute' => 'name'])]]);
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function description(array $input): ?string
    {
        $text = trim((string) (is_scalar($input['description'] ?? null) ? $input['description'] : ''));

        return $text === '' ? null : mb_substr($text, 0, 1000);
    }

    private function latestVersion(VisualFlow $flow): ?ApprovalFlowVersion
    {
        /** @var ApprovalFlowVersion|null */
        return $flow->versions()->orderByDesc('version')->first();
    }

    private function draftVersion(VisualFlow $flow): ApprovalFlowVersion
    {
        $latest = $this->latestVersion($flow);
        if ($latest !== null && $latest->published_at === null) {
            return $latest;
        }

        /** @var ApprovalFlowVersion */
        return $flow->versions()->create(['version' => ($latest->version ?? 0) + 1, 'graph' => $latest->graph ?? $this->skeleton()]);
    }
}
