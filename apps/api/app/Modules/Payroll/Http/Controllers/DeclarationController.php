<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Application\Declarations\BankFileTemplates;
use App\Modules\Payroll\Application\Declarations\DeclarationGenerators;
use App\Modules\Payroll\Application\Declarations\RegistryEvents;
use App\Modules\Payroll\Application\Declarations\StatutoryFilings;
use App\Modules\Payroll\Application\Declarations\WithholdingCertificates;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\SensitiveFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * DEC-1 a DEC-6 · Declaraciones y archivos: mapeo de conceptos a la Tabla 22, PLAME, T-Registro, AFPnet, plantillas
 * de archivo bancario y certificados de quinta. El alcance por empresa (y por trabajador en los certificados) se
 * aplica en la consulta: un recurso de otra organización o fuera del alcance responde 404 (reglas 1 y 6). Los
 * montos de los certificados viajan solo con el permiso del libro y su lectura queda registrada (regla 7).
 */
final class DeclarationController extends Controller
{
    public function __construct(
        private readonly StatutoryFilings $filings,
        private readonly DeclarationGenerators $generators,
        private readonly RegistryEvents $registry,
        private readonly BankFileTemplates $templates,
        private readonly WithholdingCertificates $certificates,
        private readonly DataScope $scope,
        private readonly SensitiveFields $sensitive,
    ) {}

    // ---- DEC-2 · DEC-3 · DEC-4 · Declaraciones (ME-24) ----

    public function index(Request $request): JsonResponse
    {
        $filter = (array) $request->query('filter', []);
        $data = $this->filings->list($this->entities($request, 'payroll.declaration_files.view'), [
            'legal_entity_id' => self::uuidOrNull($filter['legal_entity_id'] ?? null), 'kind' => self::str($filter['kind'] ?? null),
            'status' => self::str($filter['status'] ?? null), 'period_code' => self::str($filter['period_code'] ?? null),
        ]);

        return response()->json(['data' => $data, 'meta' => ['total' => count($data), 'page' => 1, 'per_page' => max(1, count($data)), 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->filingInput($request, 'payroll.declaration_files.create');

        return response()->json(['data' => $this->filings->create($data, $this->userId($request))], 201);
    }

    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->filings->preview($this->filingInput($request, 'payroll.declaration_files.create'))]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.view');

        return response()->json(['data' => $this->filings->show($id)]);
    }

    public function validateFiling(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.validate');

        return response()->json(['data' => $this->filings->validate($id, $this->userId($request))]);
    }

    public function markSubmitted(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.mark_submitted');
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:120'], 'submitted_at' => ['sometimes', 'nullable', 'date'],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $doc = $this->document($data['document_id'] ?? null);

        return response()->json(['data' => $this->filings->markSubmitted($id, (string) $data['external_reference'], $data['submitted_at'] ?? null, $doc, $this->userId($request))]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.mark_submitted');
        $data = $request->validate([
            'liquidation' => ['sometimes', 'nullable', 'array'], 'liquidation.contribution' => ['sometimes', 'numeric'],
            'liquidation.commission' => ['sometimes', 'numeric'], 'liquidation.premium' => ['sometimes', 'numeric'],
            'document_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $liquidation = isset($data['liquidation']) ? array_map('strval', (array) $data['liquidation']) : null;

        return response()->json(['data' => $this->filings->accept($id, $liquidation, $this->document($data['document_id'] ?? null), $this->userId($request))]);
    }

    public function observe(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.mark_submitted');
        $data = $request->validate(['observation' => ['required', 'string', 'max:2000']]);

        return response()->json(['data' => $this->filings->observe($id, (string) $data['observation'], $this->userId($request))]);
    }

    public function rectify(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.rectify');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $this->filings->rectify($id, (string) $data['reason'], $this->userId($request))]);
    }

    public function files(Request $request, string $id): JsonResponse
    {
        $this->locate($request, $id, 'payroll.declaration_files.view');
        $rows = $this->filings->filesOf($id);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function download(Request $request, string $id, string $fileId): Response
    {
        $this->locate($request, $id, 'payroll.declaration_files.view');
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $fileId) === 1, 404);
        $file = $this->filings->download($id, $fileId, $this->userId($request));

        return response($file['contents'], 200, ['Content-Type' => $file['mime'], 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"']);
    }

    // ---- DEC-1 · Mapeo de conceptos ----

    public function mappingCheck(Request $request): JsonResponse
    {
        $periodId = self::uuidOrNull($request->query('payroll_period_id'));
        if ($periodId === null) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.period_required'),
                [['field' => 'payroll_period_id', 'rule' => 'required', 'message' => (string) trans('payroll.declarations.period_required')]]);
        }
        $q = DB::table('payroll_periods AS p')->join('payroll_groups AS g', 'g.id', '=', 'p.payroll_group_id')->where('p.id', $periodId);
        $this->scope->applyLegalEntity($q, $request, 'payroll.declaration_files.view', 'g.legal_entity_id');
        $p = $q->first(['p.id', 'p.ends_on']);
        abort_unless($p !== null, 404);
        $run = $this->generators->closedRun((string) $p->id)
            ?? DB::table('payroll_runs')->where('payroll_period_id', $p->id)->whereNotIn('status', ['discarded'])->where('kind', '<>', 'simulation')->orderByDesc('run_number')->value('id');
        $rows = $run === null ? [] : $this->generators->unmappedConcepts((string) $run, substr((string) $p->ends_on, 0, 10));

        return response()->json(['data' => ['payroll_period_id' => (string) $p->id, 'payroll_run_id' => $run === null ? null : (string) $run, 'complete' => $rows === [], 'unmapped' => $rows]]);
    }

    public function conceptMappings(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 && DB::table('payroll_concepts')->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        $rows = DB::table('concept_statutory_mappings')->where('payroll_concept_id', $id)->orderByDesc('valid_from')->get()
            ->map(fn ($r): array => ['id' => (string) $r->id, 'filing_kind' => (string) $r->filing_kind, 'statutory_code' => (string) $r->statutory_code,
                'amount_role' => (string) $r->amount_role, 'verified' => (bool) $r->verified, 'valid_from' => substr((string) $r->valid_from, 0, 10),
                'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10)])->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function layouts(Request $request): JsonResponse
    {
        $q = DB::table('statutory_file_layouts')->orderBy('filing_kind')->orderBy('structure_code')->orderByDesc('valid_from');
        $kind = self::str(((array) $request->query('filter', []))['filing_kind'] ?? null);
        if ($kind !== null) {
            $q->where('filing_kind', $kind);
        }
        $rows = $q->get()->map(fn ($r): array => $this->layout($r))->values()->all();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function layoutShow(string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $r = DB::table('statutory_file_layouts')->where('id', $id)->first();
        abort_unless($r !== null, 404);

        return response()->json(['data' => $this->layout($r)]);
    }

    /** @return array<string, mixed> */
    private function layout(\stdClass $r): array
    {
        return ['id' => (string) $r->id, 'country' => (string) $r->country_code, 'filing_kind' => (string) $r->filing_kind, 'structure_code' => (string) $r->structure_code,
            'name' => (string) $r->name, 'file_name_pattern' => (string) $r->file_name_pattern, 'file_extension' => $r->file_extension === null ? null : (string) $r->file_extension,
            'layout_version' => (string) $r->layout_version, 'field_separator' => $r->field_separator === null ? null : (string) $r->field_separator,
            'encoding' => (string) $r->encoding, 'line_ending' => (string) $r->line_ending, 'fields' => json_decode((string) $r->fields, true),
            'verified' => (bool) $r->verified, 'source' => $r->source === null ? null : (string) $r->source,
            'valid_from' => substr((string) $r->valid_from, 0, 10), 'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10)];
    }

    // ---- DEC-3 · T-Registro ----

    public function registryEvents(Request $request): JsonResponse
    {
        $entity = self::uuidOrNull($request->query('legal_entity_id'));
        $status = self::str($request->query('status'));
        $out = $this->registry->list($this->entities($request, 'payroll.declaration_files.view'), $entity, $status);

        return response()->json(['data' => $out['data'], 'meta' => ['total' => count($out['data']), 'page' => 1, 'per_page' => max(1, count($out['data'])), 'next_cursor' => null, 'deadlines' => $out['deadlines']]]);
    }

    public function syncRegistry(Request $request): JsonResponse
    {
        $data = $request->validate(['legal_entity_id' => ['required', 'uuid']]);
        $this->entity($request, (string) $data['legal_entity_id'], 'payroll.declaration_files.create');

        return response()->json(['data' => $this->registry->sync((string) $data['legal_entity_id'])]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $entity = self::uuidOrNull($request->query('legal_entity_id'));
        if ($entity === null) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.entity_required'),
                [['field' => 'legal_entity_id', 'rule' => 'required', 'message' => (string) trans('payroll.declarations.entity_required')]]);
        }
        $this->entity($request, $entity, 'payroll.declaration_files.view');
        $rows = $this->registry->reconciliation($entity);

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    // ---- DEC-5 · Plantillas de archivo bancario ----

    public function bankTemplates(): JsonResponse
    {
        $rows = $this->templates->list();

        return response()->json(['data' => $rows, 'meta' => ['total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'next_cursor' => null]]);
    }

    public function createBankTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'], 'bank_code' => ['required', 'string', 'max:20'], 'name' => ['required', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::in(['salary', 'cts', 'other'])], 'definition' => ['required', 'array'], 'verified' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return response()->json(['data' => $this->templates->create($data)], 201);
    }

    public function updateBankTemplate(Request $request, string $id): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 && DB::table('bank_file_templates')->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        $data = $request->validate([
            'bank_code' => ['sometimes', 'string', 'max:20'], 'name' => ['sometimes', 'string', 'max:120'], 'kind' => ['sometimes', Rule::in(['salary', 'cts', 'other'])],
            'definition' => ['sometimes', 'array'], 'verified' => ['sometimes', 'boolean'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'source' => ['sometimes', 'nullable', 'string', 'max:500'], 'lock_version' => ['sometimes', 'integer'],
        ]);

        return response()->json(['data' => $this->templates->update($id, $data)]);
    }

    // ---- DEC-6 · Certificados de quinta ----

    public function certificates(Request $request): JsonResponse
    {
        $q = DB::table('employments')->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, 'payroll.fifth_category_ledger.view', 'id');
        $visible = array_values($q->pluck('id')->map(fn ($v): string => (string) $v)->all());
        $filter = (array) $request->query('filter', []);
        $year = isset($filter['fiscal_year']) && is_numeric($filter['fiscal_year']) ? (int) $filter['fiscal_year'] : null;
        $rows = $this->certificates->list($visible, self::uuidOrNull($filter['employment_id'] ?? null), $year);
        $persons = array_map(fn (array $r): string => (string) DB::table('employments')->where('id', $r['employment_id'])->value('person_id'), $rows);
        $data = $this->sensitive->presentMany('WithholdingCertificate', $rows, $this->permissions($request), 'api', $persons);

        return response()->json(['data' => $data, 'meta' => ['total' => count($data), 'page' => 1, 'per_page' => max(1, count($data)), 'next_cursor' => null]]);
    }

    public function issueCertificate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'], 'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'reason' => ['required', Rule::in(WithholdingCertificates::REASONS)],
        ]);
        $this->employment($request, (string) $data['employment_id'], 'payroll.declaration_files.create');
        $row = $this->certificates->issue((string) $data['employment_id'], (int) $data['fiscal_year'], (string) $data['reason'], $this->userId($request));
        $person = (string) DB::table('employments')->where('id', $data['employment_id'])->value('person_id');

        return response()->json(['data' => $this->sensitive->present('WithholdingCertificate', $row, $this->permissions($request), 'api', $person)], 201);
    }

    public function downloadCertificate(Request $request, string $id): Response
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $employment = $this->certificates->employmentOf($id);
        abort_unless($employment !== null, 404);
        $this->employment($request, $employment, 'payroll.fifth_category_ledger.view');
        $file = $this->certificates->download($id, $this->userId($request));

        return response($file['contents'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$file['name'].'"']);
    }

    // ---- Ayudantes ----

    /** @return array{legal_entity_id: string, kind: string, period_code?: string|null, payroll_period_id?: string|null} */
    private function filingInput(Request $request, string $permission): array
    {
        $data = $request->validate([
            'legal_entity_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(StatutoryFilings::KINDS)],
            'period_code' => ['sometimes', 'nullable', 'string', 'regex:/^\d{6}$/'], 'payroll_period_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $this->entity($request, (string) $data['legal_entity_id'], $permission);

        return ['legal_entity_id' => (string) $data['legal_entity_id'], 'kind' => (string) $data['kind'],
            'period_code' => $data['period_code'] ?? null, 'payroll_period_id' => $data['payroll_period_id'] ?? null];
    }

    private function locate(Request $request, string $id, string $permission): void
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $entity = $this->filings->entityOf($id);
        abort_unless($entity !== null, 404);
        $entities = $this->entities($request, $permission);
        abort_unless($entities === null || in_array($entity, $entities, true), 404);
    }

    /** La empresa debe existir en la organización y estar en el alcance (404 si no; regla 1). */
    private function entity(Request $request, string $id, string $permission): void
    {
        $exists = DB::table('legal_entities')->where('id', $id)->whereNull('deleted_at')->exists();
        $entities = $this->entities($request, $permission);
        if (! $exists || ($entities !== null && ! in_array($id, $entities, true))) {
            throw new AyniException('not_found', 404);
        }
    }

    private function employment(Request $request, string $id, string $permission): void
    {
        $q = DB::table('employments')->where('id', $id)->whereNull('deleted_at');
        $this->scope->applyEmployment($q, $request, $permission, 'id');
        if (! $q->exists()) {
            throw new AyniException('not_found', 404);
        }
    }

    private function document(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (! DB::table('documents')->where('id', $id)->whereNull('deleted_at')->exists()) {
            throw new AyniException('validation_failed', 422, (string) trans('payroll.declarations.document_missing'),
                [['field' => 'document_id', 'rule' => 'exists', 'message' => (string) trans('payroll.declarations.document_missing')]]);
        }

        return $id;
    }

    /** @return list<string>|null */
    private function entities(Request $request, string $permission): ?array
    {
        $userId = $this->userId($request);
        if ($userId === null) {
            return [];
        }
        $ids = $this->scope->legalEntityIds($userId, $permission);

        return $ids === null ? null : array_values(array_map('strval', $ids));
    }

    private function permissions(Request $request): EffectivePermissions
    {
        $p = $request->attributes->get('effective_permissions');

        return $p instanceof EffectivePermissions ? $p : new EffectivePermissions([], [], []);
    }

    private function userId(Request $request): ?string
    {
        $u = $request->user();

        return $u === null ? null : (string) $u->getAuthIdentifier();
    }

    private static function uuidOrNull(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9a-f-]{36}$/i', $v) === 1 ? $v : null;
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
