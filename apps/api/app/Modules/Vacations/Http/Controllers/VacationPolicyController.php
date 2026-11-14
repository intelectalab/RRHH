<?php

namespace App\Modules\Vacations\Http\Controllers;

use App\Modules\Vacations\Application\VacationPolicyCatalog;
use App\Modules\Vacations\Domain\VacationPolicyStateMachine;
use App\Modules\Vacations\Infrastructure\Models\VacationPolicy;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * VAC-1 · Políticas de vacaciones por empresa, régimen y categoría, con reglas con vigencia.
 * Leer: time.vacation_policies.view. Crear, cambiar, archivar y restaurar: time.vacation_policies.update.
 * Cambiar una regla desde una fecha cierra la regla vigente e inserta otra (?as_of= para leer a una fecha).
 */
final class VacationPolicyController
{
    public function __construct(
        private readonly VacationPolicyCatalog $catalog,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $filters = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:status,legal_entity_id,labor_regime_code'],
            'filter.status' => ['sometimes', Rule::in(['active', 'archived'])],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.labor_regime_code' => ['sometimes', 'string', 'max:60'],
            'as_of' => ['sometimes'],
        ]);
        if ($filters->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['status', 'legal_entity_id', 'labor_regime_code']);
        }
        $query = VacationPolicy::query();
        foreach (['status', 'legal_entity_id', 'labor_regime_code'] as $f) {
            $value = $request->input('filter.'.$f);
            if (is_string($value)) {
                $query->where($f, $value);
            }
        }
        // Las políticas de una empresa fuera del alcance del usuario no se muestran (regla 6).
        $entities = $this->scope->legalEntityIds((string) $request->user()?->getAuthIdentifier(), 'time.vacation_policies.view');
        if ($entities !== null) {
            $query->where(fn ($q) => $q->whereNull('legal_entity_id')->orWhereIn('legal_entity_id', $entities));
        }
        $rows = array_map(fn (VacationPolicy $p) => $this->catalog->present($p, $asOf), array_values($query->orderBy('status')->orderBy('name')->get()->all()));

        return response()->json(['data' => $rows, 'meta' => ['per_page' => max(1, count($rows)), 'next_cursor' => null, 'as_of' => $asOf]]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->present($this->find($request, $id, 'time.vacation_policies.view'), $this->asOf($request))]);
    }

    public function rules(Request $request, string $id): JsonResponse
    {
        $policy = $this->find($request, $id, 'time.vacation_policies.view');

        return response()->json(['data' => array_map(fn ($r) => $this->catalog->presentRule($r), $this->catalog->history($policy))]);
    }

    /** Límites legales del régimen a la fecha, para configurar y explicar una política. */
    public function legal(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'labor_regime_code' => ['required', 'string', Rule::exists('labor_regimes', 'code')],
            'as_of' => ['sometimes'],
        ]);
        if ($v->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['labor_regime_code', 'as_of']);
        }

        return response()->json(['data' => $this->catalog->legalLimits((string) $request->query('labor_regime_code'), $this->asOf($request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:160'],
        ] + $this->scopeRules() + $this->ruleRules());
        if (VacationPolicy::query()->withTrashed()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('vacations.code_exists'));
        }
        $this->assertEntityInScope($request, $data['legal_entity_id'] ?? null);
        $from = $data['effective_from'] ?? now()->toDateString();
        $result = $this->catalog->create($data, (array) ($data['rules'] ?? []), $from);

        return response()->json(['data' => $this->catalog->present($result['policy'], $from), 'meta' => ['warnings' => $result['warnings']]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $policy = $this->find($request, $id, 'time.vacation_policies.update');
        if ($policy->status === 'archived') {
            throw new BusinessRuleViolatedException('vacation_policy_archived', (string) trans('vacations.archived'));
        }
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:160']] + $this->scopeRules() + $this->ruleRules());
        if (array_key_exists('legal_entity_id', $data)) {
            $this->assertEntityInScope($request, $data['legal_entity_id']);
        }
        $from = $data['effective_from'] ?? now()->toDateString();
        $result = $this->catalog->update($policy, $data, (array) ($data['rules'] ?? []), $from);

        return response()->json(['data' => $this->catalog->present($result['policy'], $from), 'meta' => ['warnings' => $result['warnings']]]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, VacationPolicyStateMachine::ARCHIVE);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, VacationPolicyStateMachine::RESTORE);
    }

    private function transition(Request $request, string $id, string $action): JsonResponse
    {
        $policy = $this->find($request, $id, 'time.vacation_policies.update');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->catalog->present($this->catalog->transition($policy, $action, $data['reason'] ?? null), now()->toDateString())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeRules(): array
    {
        return [
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('legal_entities', 'id')],
            'labor_regime_code' => ['sometimes', 'nullable', 'string', Rule::exists('labor_regimes', 'code')],
            'job_category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('job_categories', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleRules(): array
    {
        $days = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:365', 'regex:/^\d{1,3}(\.\d{1,2})?$/'];

        return [
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'rules' => ['sometimes', 'array'],
            'rules.days_per_year' => $days,
            'rules.counts_calendar_days' => ['sometimes', 'boolean'],
            'rules.min_days_per_request' => $days,
            'rules.allow_advance' => ['sometimes', 'boolean'],
            'rules.max_advance_days' => $days,
            'rules.allow_sale' => ['sometimes', 'boolean'],
            'rules.max_sale_days' => $days,
            'rules.max_accumulated_periods' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            'rules.requires_hr_validation' => ['sometimes', 'boolean'],
            'rules.approval_workflow_definition_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('workflow_definitions', 'id')],
        ];
    }

    private function assertEntityInScope(Request $request, ?string $entityId): void
    {
        if ($entityId === null) {
            return;
        }
        $entities = $this->scope->legalEntityIds((string) $request->user()?->getAuthIdentifier(), 'time.vacation_policies.update');
        if ($entities !== null && ! in_array($entityId, $entities, true)) {
            throw new AyniException('validation_failed', 422, (string) trans('vacations.entity_out_of_scope'), [['field' => 'legal_entity_id', 'rule' => 'scope', 'message' => (string) trans('vacations.entity_out_of_scope')]]);
        }
    }

    private function find(Request $request, string $id, string $permission): VacationPolicy
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $policy = VacationPolicy::query()->whereKey($id)->first();
        abort_unless($policy instanceof VacationPolicy, 404);
        $entities = $this->scope->legalEntityIds((string) $request->user()?->getAuthIdentifier(), $permission);
        abort_if($entities !== null && $policy->legal_entity_id !== null && ! in_array($policy->legal_entity_id, $entities, true), 404);

        return $policy;
    }

    private function asOf(Request $request): string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null) {
            return now()->toDateString();
        }
        if (! is_string($asOf) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1 || strtotime($asOf) === false) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }

        return $asOf;
    }
}
