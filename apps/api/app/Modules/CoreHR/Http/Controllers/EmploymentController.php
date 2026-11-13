<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\EmploymentPresenter;
use App\Modules\CoreHR\Application\EmploymentService;
use App\Modules\CoreHR\Application\IdentityDocuments;
use App\Modules\CoreHR\Application\TerminationReasonCatalog;
use App\Modules\CoreHR\Application\TerminationService;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Shared\Audit\ActivityLogger;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Labor\ContractTypeSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use App\Shared\Query\TableQuery;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * COL-2 · COL-9 · Colaboradores (relaciones laborales): listado con alcance aplicado en la consulta,
 * filtros y orden en el servidor; ficha básica; ingreso; activar y anular (ME-01). El sueldo es sensible:
 * sin people.employments.salary.view no viaja ni se filtra, y cada lectura queda registrada.
 * COL-10 · COL-11 · La ficha muestra las demás relaciones de la persona (reingresos y relaciones simultáneas,
 * dentro del alcance de quien consulta) y el cese registrado; registrar y revertir el cese son acciones.
 */
final class EmploymentController
{
    private const SCHEMA = 'Employment';

    private const FILTERS = ['status', 'legal_entity_id', 'site_id', 'org_unit_id', 'job_profile_id', 'position_id', 'manager_employment_id', 'kind'];

    private const STATUSES = ['pending', 'active', 'suspended', 'terminating', 'terminated', 'cancelled'];

    private const SORTS = [
        'person_name' => 'p.paternal_surname', 'employee_code' => 'employments.employee_code', 'hire_date' => 'employments.hire_date',
        'status' => 'employments.status', 'created_at' => 'employments.created_at', 'updated_at' => 'employments.updated_at',
    ];

    public function __construct(
        private readonly EmploymentService $employments,
        private readonly TerminationService $terminations,
        private readonly EmploymentPresenter $presenter,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
        private readonly ParameterResolver $parameters,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $permissions = $this->permissions($request);
        $this->sensitive->guardQuery(self::SCHEMA, $request, $permissions);
        $query = $this->filtered($request);
        $page = (new TableQuery(self::SORTS, self::FILTERS))->page($query, $request, 'person_name');

        return response()->json([
            'data' => $this->sensitive->presentMany(self::SCHEMA, $this->presenter->presentMany($this->ordered($page['ids'])), $permissions),
            'meta' => $page['meta'],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $employment = $this->find($request, $id, 'people.employments.view');
        app(ActivityLogger::class)->record('record.viewed', 'employment', $employment->id);

        return $this->respond($request, $employment);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());
        $this->guardEntityInScope($request, (string) $input['legal_entity_id']);
        app(ContractTypeSource::class)->ensureCountryTypes();
        $employment = $this->employments->hire($input, $this->userId($request), $this->permissions($request)->has('people.employments.salary.edit'));

        return $this->respond($request, $employment, 201);
    }

    /**
     * Datos propios de la relación (contacto laboral, tipo, categoría, fechas de antigüedad y prueba).
     * El estado no se edita: cambia solo por acciones (regla 3); lo que vive con vigencia, por HIS.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $employment = $this->find($request, $id, 'people.employments.update');
        if ($request->has('status')) {
            throw ValidationException::withMessages(['status' => [(string) trans('people.status_by_action')]]);
        }
        $input = $request->validate([
            'kind' => ['sometimes', Rule::in(['employee', 'intern', 'apprentice'])],
            'worker_category' => ['sometimes', Rule::in(['executive', 'employee', 'worker'])],
            'seniority_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'work_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'work_phone_ext' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');
        if ($ifMatch !== '' && (int) $ifMatch !== $employment->lock_version) {
            throw new AyniException('conflict', 409, (string) trans('people.version_conflict'));
        }
        $before = array_intersect_key($employment->toArray(), $input);
        if (isset($input['work_email']) && is_string($input['work_email'])) {
            $input['work_email'] = mb_strtolower($input['work_email']);
        }
        DB::transaction(function () use ($employment, $input, $before): void {
            $employment->fill($input);
            $employment->lock_version++;
            $employment->save();
            $this->audit->record('employment.updated', 'employment', $employment->id, $before, $input);
        });

        return $this->respond($request, $employment->refresh());
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        $employment = $this->find($request, $id, 'people.employments.activate');

        return $this->respond($request, $this->employments->activate($employment, $this->userId($request)));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $employment = $this->find($request, $id, 'people.employments.update');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'comment' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $reason = $data['reason'] ?? $data['comment'] ?? null;

        return $this->respond($request, $this->employments->cancel($employment, is_string($reason) ? $reason : null, $this->userId($request)));
    }

    /** COL-11 · Registrar el cese (Activa o Suspendida → En proceso de cese; Cesada si la fecha ya llegó). */
    public function terminate(Request $request, string $id): JsonResponse
    {
        $employment = $this->find($request, $id, 'people.employments.terminate');
        $input = $request->validate([
            'termination_reason_id' => ['required', 'uuid'],
            'termination_date' => ['required', 'date_format:Y-m-d'],
            'last_working_day' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notice_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'initiated_by' => ['sometimes', 'nullable', Rule::in(['employee', 'employer', 'mutual'])],
            'detail' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_rehire_eligible' => ['sometimes', 'nullable', 'boolean'],
        ]);
        $result = $this->terminations->register($employment, $input, $this->userId($request));
        $response = $this->respond($request, $result['employment']);
        $payload = (array) $response->getData(true);
        $payload['meta'] = ['warnings' => $result['warnings']];

        return $response->setData($payload);
    }

    /** COL-11 · Revertir el cese antes de su fecha (En proceso de cese → Activa). */
    public function cancelTermination(Request $request, string $id): JsonResponse
    {
        $employment = $this->find($request, $id, 'people.employments.cancel_termination');
        $data = $request->validate(['reason' => ['sometimes', 'nullable', 'string', 'max:500'], 'comment' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $reason = $data['reason'] ?? $data['comment'] ?? null;

        return $this->respond($request, $this->terminations->revert($employment, is_string($reason) ? $reason : null, $this->userId($request)));
    }

    /** COL-11 · Motivos de cese de la organización (Tabla 17 del paquete de país y los propios). */
    public function terminationReasons(TerminationReasonCatalog $reasons): JsonResponse
    {
        return response()->json(['data' => $reasons->all()]);
    }

    /** Opciones del formulario de ingreso, con el alcance de empresa de quien registra. */
    public function options(Request $request): JsonResponse
    {
        app(ContractTypeSource::class)->ensureCountryTypes();
        $allowed = $this->allowedEntities($request, 'people.employments.create');
        $entities = DB::table('legal_entities')->where('status', 'active')->whereNull('deleted_at')
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?? []));
        $rows = fn ($q, array $cols) => $q->get($cols)->map(fn ($r) => (array) $r)->values()->all();
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');

        return response()->json(['data' => [
            'legal_entities' => $rows($entities->orderBy('legal_name'), ['id', 'legal_name as name', 'currency']),
            'job_profiles' => $rows(DB::table('job_profiles')->where('status', 'active')->whereNull('deleted_at')->orderBy('name'), ['id', 'name', 'code']),
            'positions' => $rows(DB::table('positions')->whereIn('status', ['approved', 'open', 'recruiting'])->whereNull('deleted_at')
                ->when($allowed !== null, fn ($q) => $q->whereIn('legal_entity_id', $allowed ?? []))->orderBy('code'),
                ['id', 'code', 'title', 'legal_entity_id', 'job_profile_id', 'org_unit_id', 'site_id', 'cost_center_id', 'seats', 'status']),
            'org_units' => $rows(DB::table('org_units')->where('status', 'active')->whereNull('deleted_at')->orderBy('name'), ['id', 'name', 'legal_entity_id']),
            'sites' => $rows(DB::table('sites')->where('status', 'active')->whereNull('deleted_at')->orderBy('name'), ['id', 'name', 'legal_entity_id']),
            'cost_centers' => $rows(DB::table('cost_centers')->where('status', 'active')->whereNull('deleted_at')->orderBy('name'), ['id', 'name', 'code', 'legal_entity_id']),
            'contract_types' => $rows(DB::table('contract_types')->whereNull('deleted_at')->orderByDesc('is_system')->orderBy('name'),
                ['id', 'code', 'name', 'category', 'requires_end_date', 'requires_objective_cause']),
            'labor_regimes' => $rows(DB::table('labor_regimes')->where('country_code', $country)->orderBy('name'), ['code', 'name']),
            'entity_regimes' => $rows(DB::table('legal_entity_regimes')->where('valid_from', '<=', now()->toDateString())
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString())), ['legal_entity_id', 'labor_regime_code']),
            'document_types' => IdentityDocuments::TYPES,
            'rules' => [
                'position_required' => $this->parameters->value('posiciones.contratar_solo_con_posicion') === true,
                'automatic_code' => $this->parameters->value('colaboradores.codigo_automatico') !== false,
                'register_sex' => $this->parameters->value('colaboradores.registrar_sexo') !== false,
            ],
        ]]);
    }

    /**
     * @return Builder<Employment>
     */
    private function filtered(Request $request): Builder
    {
        $query = Employment::query()->join('people as p', 'p.id', '=', 'employments.person_id')
            ->leftJoin(DB::raw('('.EmploymentPresenter::CURRENT_ASSIGNMENT_SQL.') as ca'), 'ca.employment_id', '=', 'employments.id')
            ->select('employments.*');
        $this->scope->applyEmployment($query, $request, 'people.employments.view', 'employments.id');
        $selected = DataScope::selectedLegalEntity($request);
        if ($selected !== null) {
            $query->where('employments.legal_entity_id', $selected);
        }

        $status = TableQuery::filter($request, 'status');
        if ($status !== null) {
            $wanted = explode(',', $status);
            if (array_diff($wanted, self::STATUSES) !== []) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
            }
            $query->whereIn('employments.status', $wanted);
        }
        $kind = TableQuery::filter($request, 'kind');
        if ($kind !== null) {
            if (! in_array($kind, ['employee', 'intern', 'apprentice'], true)) {
                throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
            }
            $query->where('employments.kind', $kind);
        }
        foreach (['site_id', 'job_profile_id', 'position_id', 'manager_employment_id'] as $f) {
            $value = $this->uuidFilter($request, $f);
            if ($value !== null) {
                $query->where('ca.'.$f, $value);
            }
        }
        $unit = $this->uuidFilter($request, 'org_unit_id');
        if ($unit !== null) {
            // La unidad incluye sus subunidades.
            $query->whereRaw('ca.org_unit_id IN (WITH RECURSIVE t AS (SELECT id FROM org_units WHERE id = ? UNION SELECT c.id FROM org_units c JOIN t ON c.parent_id = t.id) SELECT id FROM t)', [$unit]);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $normalized = IdentityDocuments::normalize($q);
            $hashes = $normalized === '' ? [] : array_map(fn (string $t) => IdentityDocuments::hash($t, $normalized), IdentityDocuments::TYPES);
            $query->where(function ($w) use ($like, $hashes): void {
                $w->whereRaw("concat_ws(' ', p.first_names, p.preferred_name, p.paternal_surname, p.maternal_surname) ilike ?", [$like])
                    ->orWhere('employments.employee_code', 'ilike', $like);
                if ($hashes !== []) {
                    // El documento se busca por su huella exacta (nunca en claro).
                    $w->orWhereIn('employments.person_id', DB::table('person_documents')->whereIn('number_hash', $hashes)->whereNull('deleted_at')->select('person_id'));
                }
            });
        }

        return $query;
    }

    private function uuidFilter(Request $request, string $name): ?string
    {
        $value = TableQuery::filter($request, $name);
        if ($value !== null && preg_match('/^[0-9a-f-]{36}$/i', $value) !== 1) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }

        return $value;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, Employment>
     */
    private function ordered(array $ids): array
    {
        $models = Employment::query()->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            $e = $models->get($id);
            if ($e instanceof Employment) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'person_id' => ['required_without:person', 'nullable', 'uuid'],
            'person' => ['required_without:person_id', 'nullable', 'array'],
            'person.first_names' => ['required_with:person', 'string', 'min:1', 'max:100'],
            'person.paternal_surname' => ['required_with:person', 'string', 'min:1', 'max:100'],
            'person.maternal_surname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'person.preferred_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'person.document_type' => ['required_with:person', Rule::in(IdentityDocuments::TYPES)],
            'person.document_number' => ['required_with:person', 'string', 'max:30'],
            'person.document_country' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'person.nationality_code' => ['sometimes', 'nullable', 'string', 'size:2', Rule::exists('countries', 'code')],
            'person.birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'person.sex' => ['sometimes', 'nullable', Rule::in(['female', 'male', 'unspecified'])],
            'person.marital_status' => ['sometimes', 'nullable', Rule::in(['single', 'married', 'cohabiting', 'divorced', 'widowed'])],
            'person.personal_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'person.mobile_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'legal_entity_id' => ['required', 'uuid'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'kind' => ['sometimes', Rule::in(['employee', 'intern', 'apprentice'])],
            'worker_category' => ['sometimes', Rule::in(['executive', 'employee', 'worker'])],
            'hire_date' => ['required', 'date_format:Y-m-d'],
            'seniority_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'probation_ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:hire_date'],
            'job_profile_id' => ['sometimes', 'nullable', 'uuid'],
            'position_id' => ['sometimes', 'nullable', 'uuid'],
            'org_unit_id' => ['sometimes', 'nullable', 'uuid'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'cost_center_id' => ['sometimes', 'nullable', 'uuid'],
            'manager_employment_id' => ['sometimes', 'nullable', 'uuid'],
            'work_modality' => ['sometimes', Rule::in(['onsite', 'remote', 'hybrid'])],
            'labor_regime_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'health_scheme' => ['sometimes', Rule::in(['essalud', 'eps', 'sis', 'other'])],
            'contract_type_id' => ['required', 'uuid'],
            'contract_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'contract_objective_cause' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'base_salary' => ['sometimes', 'nullable', 'array:amount,currency'],
            'base_salary.amount' => ['required_with:base_salary', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'base_salary.currency' => ['required_with:base_salary', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'pay_frequency' => ['sometimes', Rule::in(['monthly', 'biweekly', 'weekly', 'daily', 'hourly'])],
            'work_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'work_phone_ext' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    private function find(Request $request, string $id, string $permission): Employment
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $query = Employment::query()->whereKey($id);
        $this->scope->applyEmployment($query, $request, $permission, 'employments.id');
        $employment = $query->first();
        abort_unless($employment instanceof Employment, 404);

        return $employment;
    }

    /**
     * @return array<int, string>|null null = toda la organización
     */
    private function allowedEntities(Request $request, string $permission): ?array
    {
        $user = $request->user();

        return $user === null ? [] : $this->scope->legalEntityIds((string) $user->getAuthIdentifier(), $permission);
    }

    /** Con alcance de empresa solo se registran ingresos en empresas del propio alcance. */
    private function guardEntityInScope(Request $request, string $legalEntityId): void
    {
        $allowed = $this->allowedEntities($request, 'people.employments.create');
        if ($allowed !== null && ! in_array($legalEntityId, $allowed, true)) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('people.out_of_scope')]]);
        }
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user === null ? null : (string) $user->getAuthIdentifier();
    }

    /**
     * COL-10 · Las demás relaciones de la persona (reingresos y relaciones simultáneas) que quien consulta puede
     * ver con su alcance, para pasar de una a otra en la ficha.
     *
     * @return array<int, array<string, mixed>>
     */
    private function otherEmployments(Request $request, Employment $employment): array
    {
        $query = Employment::query()->where('employments.person_id', $employment->person_id)->where('employments.id', '<>', $employment->id)
            ->join('legal_entities as le', 'le.id', '=', 'employments.legal_entity_id')
            ->select(['employments.id', 'employments.legal_entity_id', 'le.legal_name', 'employments.employee_code', 'employments.hire_date',
                'employments.termination_date', 'employments.status', 'employments.rehire_of_employment_id']);
        $this->scope->applyEmployment($query, $request, 'people.employments.view', 'employments.id');

        return $query->orderByDesc('employments.hire_date')->get()->map(fn (Employment $e): array => [
            'id' => $e->id, 'legal_entity_id' => $e->legal_entity_id, 'legal_entity_name' => (string) $e->getAttribute('legal_name'),
            'employee_code' => $e->employee_code, 'hire_date' => $e->hire_date->toDateString(),
            'termination_date' => $e->termination_date?->toDateString(), 'status' => $e->status,
            'rehire_of_employment_id' => $e->rehire_of_employment_id,
        ])->values()->all();
    }

    private function respond(Request $request, Employment $employment, int $status = 200): JsonResponse
    {
        $data = $this->presenter->presentMany([$employment], withSalary: true)[0];
        if ($data['base_salary'] === null) {
            // Sin compensación registrada no hay lectura de sueldo que registrar.
            unset($data['base_salary']);
        }
        $data['termination'] = $this->terminations->current($employment, $this->permissions($request)->has('people.employments.terminate'));
        $data['other_employments'] = $this->otherEmployments($request, $employment);
        $data = $this->sensitive->present(self::SCHEMA, $data, $this->permissions($request), 'api', $employment->person_id);

        return response()->json(['data' => $data], $status)->header('ETag', '"'.$employment->lock_version.'"');
    }
}
