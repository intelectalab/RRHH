<?php

namespace App\Modules\Workflow\Application;

use App\Modules\Workflow\Domain\ApprovalFlowStateMachine;
use App\Modules\Workflow\Infrastructure\Models\ApprovalFlow;
use App\Modules\Workflow\Infrastructure\Models\ApprovalFlowVersion;
use App\Modules\Workflow\Infrastructure\Models\WorkflowStep;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * WFL-1 · Flujos de aprobación con versiones. Editar un flujo publicado abre una versión
 * borrador; publicarla no altera las solicitudes en curso (RN-WFL-08).
 *
 * steps = {levels: [{name, mode: sequential|parallel_all|parallel_any, min_approvals?, sla_hours?,
 *          approvers: [{kind: user|role|manager|manager_of_manager, user_id?, role_id?}],
 *          escalate_to?: {kind, user_id?, role_id?}}]}
 * conditions = {campo: valor | [valores]} sobre el contexto de la solicitud (RN-WFL-01).
 */
final class ApprovalFlowService
{
    public const MODES = ['sequential', 'parallel_all', 'parallel_any'];

    public const APPROVER_KINDS = ['user', 'role', 'manager', 'manager_of_manager'];

    public function __construct(
        private readonly ApprovalFlowStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(ApprovalFlow $flow): array
    {
        $latest = $this->latestVersion($flow);
        $published = $flow->current_version_id === null ? null : ApprovalFlowVersion::query()->find($flow->current_version_id);
        $graph = (array) ($latest->graph ?? []);

        return [
            'id' => $flow->id,
            'name' => $flow->name,
            'subject_type' => (string) $flow->subject_type,
            'steps' => ['levels' => array_values((array) ($graph['levels'] ?? []))],
            'conditions' => (object) ((array) ($graph['conditions'] ?? [])),
            'status' => match ($flow->status) {
                'active' => 'published', 'archived' => 'retired', default => 'draft'
            },
            'version' => $published instanceof ApprovalFlowVersion ? $published->version : 0,
            'has_draft' => $latest !== null && $latest->published_at === null && $published !== null,
            'allowed_actions' => $this->machine->allowedActions((string) $flow->status),
            'created_at' => $flow->created_at?->toIso8601String(),
            'updated_at' => $flow->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): ApprovalFlow
    {
        $graph = $this->validatedGraph($input, false);

        return DB::transaction(function () use ($input, $graph): ApprovalFlow {
            $flow = ApprovalFlow::create([
                'code' => Str::slug((string) $input['name'], '_').'_'.Str::lower(Str::random(4)),
                'name' => $input['name'],
                'kind' => 'approval',
                'subject_type' => $input['subject_type'],
                'status' => 'draft',
            ]);
            $flow->versions()->create(['version' => 1, 'graph' => $graph]);
            $this->audit->record('approval_flow.created', 'approval_flow', $flow->id, null, $this->present($flow->refresh()));

            return $flow;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(ApprovalFlow $flow, array $input): ApprovalFlow
    {
        $graph = $this->validatedGraph($input + $this->currentInput($flow), false);

        return DB::transaction(function () use ($flow, $input, $graph): ApprovalFlow {
            $before = $this->present($flow);
            $flow->fill(array_intersect_key($input, array_flip(['name', 'subject_type'])));
            if ($flow->isDirty()) {
                $flow->save();
            }

            $draft = $this->draftVersion($flow);
            $draft->forceFill(['graph' => $graph])->save();

            $this->audit->record('approval_flow.updated', 'approval_flow', $flow->id, $before, $this->present($flow->refresh()));

            return $flow;
        });
    }

    public function publish(ApprovalFlow $flow, ?string $publishedBy): ApprovalFlow
    {
        $next = $this->machine->getNextState((string) $flow->status, 'publish');
        $version = $this->latestVersion($flow);
        if ($version === null || $version->published_at !== null && $flow->status === 'active') {
            throw new BusinessRuleViolatedException('RN-WFL-08', (string) trans('workflow.nothing_to_publish'));
        }
        $this->validatedGraph(['subject_type' => $flow->subject_type] + (array) $version->graph, true);

        return DB::transaction(function () use ($flow, $version, $next, $publishedBy): ApprovalFlow {
            $before = $this->present($flow);
            if ($version->published_at === null) {
                $version->forceFill(['published_at' => now(), 'published_by_user_id' => $publishedBy])->save();
                foreach (array_values((array) ($version->graph['levels'] ?? [])) as $i => $level) {
                    $first = (array) (($level['approvers'] ?? [])[0] ?? []);
                    WorkflowStep::create([
                        'workflow_definition_version_id' => $version->id,
                        'key' => 'level_'.($i + 1),
                        'name' => (string) ($level['name'] ?? 'Nivel '.($i + 1)),
                        'kind' => 'approval',
                        'sort_order' => $i + 1,
                        'approver_kind' => $first['kind'] ?? null,
                        'approver_role_id' => $first['role_id'] ?? null,
                        'approver_user_id' => $first['user_id'] ?? null,
                        'approval_mode' => $level['mode'] ?? 'sequential',
                        'sla_hours' => isset($level['sla_hours']) ? (int) $level['sla_hours'] : null,
                        'action_config' => ['approvers' => $level['approvers'] ?? [], 'min_approvals' => $level['min_approvals'] ?? null],
                        'escalation_config' => $level['escalate_to'] ?? null,
                    ]);
                }
            }
            $flow->forceFill(['status' => $next, 'current_version_id' => $version->id])->save();
            $this->audit->record('approval_flow.published', 'approval_flow', $flow->id, $before, $this->present($flow->refresh()));

            return $flow;
        });
    }

    public function retire(ApprovalFlow $flow): ApprovalFlow
    {
        $next = $this->machine->getNextState((string) $flow->status, 'retire');

        return DB::transaction(function () use ($flow, $next): ApprovalFlow {
            $before = $this->present($flow);
            $flow->forceFill(['status' => $next])->save();
            $this->audit->record('approval_flow.retired', 'approval_flow', $flow->id, $before, $this->present($flow->refresh()));

            return $flow;
        });
    }

    /**
     * RN-WFL-01: flujo publicado más específico cuyo tipo y condiciones aplican al contexto.
     *
     * @param  array<string, mixed>  $context
     */
    public function select(string $subjectType, array $context): ?ApprovalFlowVersion
    {
        $best = null;
        $bestScore = -1;
        $flows = ApprovalFlow::query()->where('subject_type', $subjectType)->where('status', 'active')->whereNotNull('current_version_id')->orderBy('created_at')->get();
        foreach ($flows as $flow) {
            /** @var ApprovalFlowVersion|null $version */
            $version = ApprovalFlowVersion::query()->find($flow->current_version_id);
            if ($version === null) {
                continue;
            }
            $conditions = (array) ($version->graph['conditions'] ?? []);
            $matches = true;
            foreach ($conditions as $field => $expected) {
                $actual = $context[$field] ?? null;
                $ok = is_array($expected) ? in_array($actual, $expected, false) : $actual == $expected;
                if (! $ok) {
                    $matches = false;
                    break;
                }
            }
            if ($matches && count($conditions) > $bestScore) {
                $best = $version;
                $bestScore = count($conditions);
            }
        }

        return $best;
    }

    public function actorId(): ?string
    {
        $user = request()->user();

        return $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : null;
    }

    private function latestVersion(ApprovalFlow $flow): ?ApprovalFlowVersion
    {
        /** @var ApprovalFlowVersion|null */
        return $flow->versions()->orderByDesc('version')->first();
    }

    private function draftVersion(ApprovalFlow $flow): ApprovalFlowVersion
    {
        $latest = $this->latestVersion($flow);
        if ($latest !== null && $latest->published_at === null) {
            return $latest;
        }

        /** @var ApprovalFlowVersion */
        return $flow->versions()->create(['version' => ($latest->version ?? 0) + 1, 'graph' => $latest->graph ?? []]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentInput(ApprovalFlow $flow): array
    {
        $graph = (array) ($this->latestVersion($flow)->graph ?? []);

        return ['subject_type' => $flow->subject_type, 'steps' => ['levels' => $graph['levels'] ?? []], 'conditions' => $graph['conditions'] ?? []];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{levels: array<int, array<string, mixed>>, conditions: array<string, mixed>}
     */
    private function validatedGraph(array $input, bool $forPublish): array
    {
        $levels = array_values((array) (($input['steps'] ?? [])['levels'] ?? ($input['levels'] ?? [])));
        $conditions = (array) ($input['conditions'] ?? []);
        $errors = [];

        if ($forPublish && $levels === []) {
            $errors['steps'][] = (string) trans('workflow.levels_required');
        }
        foreach ($levels as $i => $level) {
            $level = (array) $level;
            if (! in_array($level['mode'] ?? 'sequential', self::MODES, true)) {
                $errors["steps.levels.{$i}.mode"][] = (string) trans('workflow.invalid_mode');
            }
            $approvers = (array) ($level['approvers'] ?? []);
            if ($approvers === []) {
                $errors["steps.levels.{$i}.approvers"][] = (string) trans('workflow.approvers_required');
            }
            foreach ($approvers as $j => $approver) {
                $approver = (array) $approver;
                $kind = $approver['kind'] ?? null;
                $uuid = fn ($v) => is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1;
                if (! in_array($kind, self::APPROVER_KINDS, true)
                    || ($kind === 'user' && ! $uuid($approver['user_id'] ?? null))
                    || ($kind === 'role' && ! $uuid($approver['role_id'] ?? null))
                    || ($kind === 'user' && ! DB::table('users')->where('id', $approver['user_id'] ?? null)->exists())
                    || ($kind === 'role' && ! DB::table('roles')->where('id', $approver['role_id'] ?? null)->whereNull('deleted_at')->exists())) {
                    $errors["steps.levels.{$i}.approvers.{$j}"][] = (string) trans('workflow.invalid_approver');
                }
            }
            if (isset($level['sla_hours']) && (! is_numeric($level['sla_hours']) || (int) $level['sla_hours'] < 1)) {
                $errors["steps.levels.{$i}.sla_hours"][] = (string) trans('workflow.invalid_sla');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['levels' => array_map(fn ($l) => (array) $l, $levels), 'conditions' => $conditions];
    }
}
