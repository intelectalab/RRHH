<?php

namespace App\Modules\Absences\Http\Controllers;

use App\Modules\Absences\Application\AbsenceTypeCatalog;
use App\Modules\Absences\Domain\AbsenceTypeRules;
use App\Modules\Absences\Domain\AbsenceTypeStateMachine;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\InvalidFilterException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * AUS-1 · Tipos de ausencia con reglas propias. Los del paquete de país traen identidad fija (código,
 * categoría, confidencialidad y duración legal) y la organización ajusta sus reglas; los tipos propios
 * se editan por completo. Toda regla cambia con vigencia (?as_of= para leer a una fecha).
 */
final class AbsenceTypeController
{
    private const FILTERS = ['category', 'status', 'requestable_by', 'legal_entity_id', 'labor_regime_code'];

    public function __construct(
        private readonly AbsenceTypeCatalog $catalog,
        private readonly AbsenceTypeStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $filters = Validator::make($request->query(), [
            'filter' => ['sometimes', 'array:category,status,requestable_by,legal_entity_id,labor_regime_code'],
            'filter.category' => ['sometimes', Rule::in(AbsenceType::CATEGORIES)],
            'filter.status' => ['sometimes', Rule::in(['active', 'archived'])],
            'filter.requestable_by' => ['sometimes', Rule::in(AbsenceTypeRules::REQUESTERS)],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'filter.labor_regime_code' => ['sometimes', 'string', 'max:60'],
        ]);
        if ($filters->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $this->catalog->ensureCountryTypes();

        $query = AbsenceType::query();
        foreach (['category', 'status'] as $f) {
            $value = $request->input('filter.'.$f);
            if (is_string($value)) {
                $query->where($f, $value);
            }
        }
        $requester = $request->input('filter.requestable_by');
        $entity = $request->input('filter.legal_entity_id');
        $regime = $request->input('filter.labor_regime_code');

        $rows = [];
        foreach ($query->orderByDesc('is_system')->orderBy('category')->orderBy('name')->get() as $type) {
            $rule = $this->catalog->rulesAt($type, $asOf);
            if ($rule !== null && (is_string($requester) || is_string($entity) || is_string($regime))) {
                $d = AbsenceTypeCatalog::domain($rule);
                if ((is_string($requester) && ! $d->canBeRequestedBy($requester))
                    || ((is_string($entity) || is_string($regime)) && ! $d->appliesTo(is_string($entity) ? $entity : null, is_string($regime) ? $regime : null))) {
                    continue;
                }
            }
            $rows[] = $this->catalog->present($type, $asOf);
        }

        return response()->json([
            'data' => $rows,
            'meta' => ['per_page' => max(1, count($rows)), 'next_cursor' => null, 'as_of' => $asOf],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->catalog->present($this->find($id), $this->asOf($request))]);
    }

    public function rules(string $id): JsonResponse
    {
        $type = $this->find($id);

        return response()->json(['data' => array_map(fn ($r) => $this->catalog->presentRule($r), $this->catalog->history($type))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->catalog->ensureCountryTypes();
        $country = $this->catalog->country();
        $data = $request->validate($this->identityRules(true, $country) + $this->ruleRules($country));
        if (AbsenceType::query()->withTrashed()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('absences.types.code_exists'));
        }
        $from = $data['effective_from'] ?? now()->toDateString();

        $type = DB::transaction(function () use ($data, $country, $from): AbsenceType {
            $t = AbsenceType::query()->create([
                'code' => $data['code'], 'name' => $data['name'], 'category' => $data['category'], 'country_code' => $country,
                'is_system' => false, 'is_confidential' => (bool) ($data['is_confidential'] ?? false),
                'legal_duration_parameter_code' => $data['legal_duration_parameter_code'] ?? null,
            ]);
            $this->catalog->changeRules($t, (array) ($data['rules'] ?? []), $from);
            $this->audit->record('absence_type.created', 'absence_type', $t->id, null, $this->snapshot($t->refresh(), $from));

            return $t;
        });

        return response()->json(['data' => $this->catalog->present($type, $from)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $type = $this->find($id);
        if ($type->status === 'archived') {
            throw new BusinessRuleViolatedException('absence_type_archived', (string) trans('absences.types.archived'));
        }
        $country = $type->country_code ?? $this->catalog->country();
        $identity = $type->is_system ? [] : $this->identityRules(false, $country);
        $data = $request->validate($identity + $this->ruleRules($country));
        if ($type->is_system && $request->hasAny(['code', 'name', 'category', 'is_confidential', 'legal_duration_parameter_code'])) {
            throw new BusinessRuleViolatedException('absence_type_is_system', (string) trans('absences.types.system_identity'));
        }
        if (isset($data['code']) && $data['code'] !== $type->code && AbsenceType::query()->withTrashed()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('absences.types.code_exists'));
        }
        $from = $data['effective_from'] ?? now()->toDateString();
        $before = $this->snapshot($type, $from);

        DB::transaction(function () use ($type, $data, $from, $before): void {
            $fields = array_intersect_key($data, array_flip(['code', 'name', 'category', 'is_confidential', 'legal_duration_parameter_code']));
            if ($fields !== []) {
                $type->fill($fields)->save();
            }
            if (isset($data['rules'])) {
                $this->catalog->changeRules($type, (array) $data['rules'], $from);
            }
            $this->audit->record('absence_type.updated', 'absence_type', $type->id, $before, $this->snapshot($type->refresh(), $from));
        });

        return response()->json(['data' => $this->catalog->present($type, $from)]);
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $type = $this->find($id);
        $next = $this->machine->getNextState($type->status, AbsenceTypeStateMachine::ARCHIVE);
        $reason = $request->input('reason');

        DB::transaction(function () use ($type, $next, $reason): void {
            $before = $type->status;
            $type->forceFill(['status' => $next])->save();
            $this->audit->record('absence_type.archived', 'absence_type', $type->id, ['status' => $before], ['status' => $next, 'reason' => is_string($reason) ? $reason : null]);
        });

        return response()->json(['data' => $this->catalog->present($type->refresh(), now()->toDateString())]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function identityRules(bool $creating, string $country): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$req, 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'name' => [$req, 'string', 'max:120'],
            'category' => [$req, Rule::in(AbsenceType::CATEGORIES)],
            'is_confidential' => ['sometimes', 'boolean'],
            // La duración de ley sale de un parámetro legal existente del país: el tipo no guarda números.
            'legal_duration_parameter_code' => ['sometimes', 'nullable', 'string', Rule::in($this->catalog->legalParameterCodes($country))],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function ruleRules(string $country): array
    {
        $concepts = $this->catalog->payrollConceptCodes($country);

        return [
            'effective_from' => ['sometimes', 'date_format:Y-m-d'],
            'rules' => ['sometimes', 'array'],
            'rules.is_paid' => ['sometimes', 'boolean'],
            'rules.affects_attendance' => ['sometimes', 'boolean'],
            'rules.unit' => ['sometimes', Rule::in(['days', 'hours'])],
            'rules.max_quantity' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:999999'],
            'rules.max_period' => ['sometimes', Rule::in(['event', 'month', 'year'])],
            'rules.min_notice_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'rules.requires_document' => ['sometimes', 'boolean'],
            'rules.document_grace_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'rules.requestable_by' => ['sometimes', Rule::in(AbsenceTypeRules::REQUESTERS)],
            'rules.approval_workflow_definition_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('workflow_definitions', 'id')->where('kind', 'approval')->whereNull('deleted_at')],
            'rules.payroll_concept_code' => ['sometimes', 'nullable', 'string', 'max:60', ...($concepts === [] ? [] : [Rule::in($concepts)])],
            'rules.statutory_suspension_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'rules.subsidy_kind' => ['sometimes', Rule::in(['none', 'illness', 'maternity'])],
            'rules.counts_as_worked_for_benefits' => ['sometimes', 'boolean'],
            'rules.day_count' => ['sometimes', Rule::in(['calendar', 'working'])],
            'rules.legal_entity_ids' => ['sometimes', 'nullable', 'array', 'max:200'],
            'rules.legal_entity_ids.*' => ['uuid', Rule::exists('legal_entities', 'id')->whereNull('deleted_at')],
            'rules.labor_regime_codes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'rules.labor_regime_codes.*' => ['string', Rule::exists('labor_regimes', 'code')],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(AbsenceType $t, string $asOf): array
    {
        $rule = $this->catalog->rulesAt($t, $asOf);

        return $t->only(['code', 'name', 'category', 'is_confidential', 'legal_duration_parameter_code', 'status'])
            + ['rules' => $rule === null ? null : $this->catalog->presentRule($rule)];
    }

    private function asOf(Request $request): string
    {
        $asOf = $request->query('as_of');
        if ($asOf === null) {
            return now()->toDateString();
        }
        if (! is_string($asOf) || Validator::make(['as_of' => $asOf], ['as_of' => ['date_format:Y-m-d']])->fails()) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['as_of']);
        }

        return $asOf;
    }

    private function find(string $id): AbsenceType
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var AbsenceType */
        return AbsenceType::query()->whereKey($id)->firstOrFail();
    }
}
