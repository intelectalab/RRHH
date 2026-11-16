<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Modules\Documents\Application\DocumentAccess;
use App\Modules\Documents\Application\DocumentGenerator;
use App\Modules\Documents\Application\DocumentTemplateService;
use App\Modules\Documents\Application\TemplateVariables;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplate;
use App\Modules\Documents\Infrastructure\Models\DocumentTemplateVersion;
use App\Shared\Exceptions\AyniException;
use App\Shared\Permissions\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOC-4 · DOC-5 · Plantillas de documentos (editor con catálogo de variables y vista previa con datos reales) y
 * generación de PDF al legajo, una a una o en lote con su avance.
 */
final class DocumentTemplateController
{
    private const STATUSES = ['draft', 'active', 'inactive'];

    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly DocumentGenerator $generator,
        private readonly TemplateVariables $variables,
        private readonly DocumentAccess $access,
        private readonly DataScope $scope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'filter' => ['sometimes', 'array'],
            'filter.status' => ['sometimes', 'string', 'max:100'],
            'filter.kind' => ['sometimes', 'string', 'in:'.implode(',', DocumentTemplateService::KINDS)],
            'filter.category_id' => ['sometimes', 'uuid'],
            'filter.legal_entity_id' => ['sometimes', 'uuid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $this->templates->ensureDefaults();
        $filter = $input['filter'] ?? [];
        $statuses = isset($filter['status']) ? explode(',', (string) $filter['status']) : [];
        if (array_diff($statuses, self::STATUSES) !== []) {
            throw new AyniException('invalid_filter', 400, (string) trans('documents.documents.invalid_filter'));
        }
        $q = isset($input['q']) ? trim((string) $input['q']) : '';
        $query = DocumentTemplate::query()
            ->when($statuses !== [], fn ($w) => $w->whereIn('status', $statuses))
            ->when(isset($filter['kind']), fn ($w) => $w->where('kind', $filter['kind']))
            ->when(isset($filter['category_id']), fn ($w) => $w->where('document_category_id', $filter['category_id']))
            ->when(isset($filter['legal_entity_id']), fn ($w) => $w->where(fn ($l) => $l->whereNull('legal_entity_id')->orWhere('legal_entity_id', $filter['legal_entity_id'])))
            ->when($q !== '', fn ($w) => $w->where(fn ($l) => $l->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('lower(code) like ?', ['%'.mb_strtolower($q).'%'])));
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? 25);
        $total = (clone $query)->count();
        /** @var list<DocumentTemplate> $rows */
        $rows = $query->orderBy('name')->forPage($page, $perPage)->get()->all();

        return response()->json(['data' => array_map(fn (DocumentTemplate $t): array => $this->present($t, false), $rows),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'next_cursor' => null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => ['required', 'in:'.implode(',', DocumentTemplateService::KINDS)],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'locale' => ['sometimes', 'in:es,en,pt'],
            'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'page_settings' => ['sometimes', 'nullable', 'array'],
        ]);
        if (isset($data['legal_entity_id']) && ! $this->access->legalEntityInScope($request, (string) $data['legal_entity_id'], 'documents.templates.create')) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('documents.templates.legal_entity_unknown')]]);
        }
        $template = $this->templates->create($data, $this->access->userId($request));

        return response()->json(['data' => $this->present($template, true)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($id), true)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = $this->find($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'legal_entity_id' => ['sometimes', 'nullable', 'uuid'],
            'locale' => ['sometimes', 'in:es,en,pt'],
            'body' => ['sometimes', 'string', 'max:100000'],
            'page_settings' => ['sometimes', 'nullable', 'array'],
            'version' => ['sometimes', 'integer'],
        ]);
        if (isset($data['legal_entity_id']) && ! $this->access->legalEntityInScope($request, (string) $data['legal_entity_id'], 'documents.templates.update')) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('documents.templates.legal_entity_unknown')]]);
        }
        $ifMatch = trim((string) $request->header('If-Match', ''), ' "W/');
        $expected = $ifMatch !== '' ? (int) $ifMatch : (isset($data['version']) ? (int) $data['version'] : null);
        unset($data['version']);

        return response()->json(['data' => $this->present($this->templates->update($template, $data, $expected, $this->access->userId($request)), true)]);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->templates->publish($this->find($id), $this->access->userId($request)), true)]);
    }

    public function retire(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->templates->retire($this->find($id)), true)]);
    }

    public function variables(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->variables->catalog($this->access->permissions($request)), 'meta' => [
            'syntax' => ['variable' => '{{grupo.variable}}', 'if' => '{{#if grupo.variable}}…{{else}}…{{/if}}', 'unless' => '{{#unless grupo.variable}}…{{/unless}}'],
        ]]);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        $template = $this->find($id);
        $data = $request->validate([
            'employment_id' => ['required', 'uuid'],
            'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
        ]);
        if (! $this->access->employmentInScope($request, (string) $data['employment_id'], 'documents.templates.generate')) {
            throw ValidationException::withMessages(['employment_id' => [(string) trans('documents.documents.owner_unknown')]]);
        }
        $result = $this->templates->preview($template, (string) $data['employment_id'], isset($data['body']) ? (string) $data['body'] : null, $this->access->permissions($request));
        if ($result['sensitive'] !== []) {
            $this->variables->logRead($this->access->userId($request), $result['person_id'], 'document_template', $template->id, $result['sensitive'], $result['sensitive_fields'], $request->ip());
        }
        unset($result['sensitive'], $result['sensitive_fields'], $result['person_id']);

        return response()->json(['data' => $result + ['employment_id' => $data['employment_id']]]);
    }

    public function generate(Request $request, string $id): JsonResponse
    {
        $template = $this->find($id);
        $data = $request->validate([
            'employment_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employment_ids.*' => ['uuid'],
        ]);
        $ids = array_values(array_unique($data['employment_ids']));
        $q = DB::table('employments')->whereIn('employments.id', $ids)->whereNull('employments.deleted_at');
        $this->scope->applyEmployment($q, $request, 'documents.templates.generate', 'employments.id');
        $inScope = $q->pluck('employments.id')->map(fn ($e): string => (string) $e)->all();
        $outside = array_values(array_diff($ids, $inScope));
        if ($inScope === [] || $outside !== []) {
            // Fuera del alcance la relación no existe para el usuario (no se revela si existe en otra parte).
            throw ValidationException::withMessages(['employment_ids' => [(string) trans('documents.templates.employments_unknown')]]);
        }
        $batch = $this->generator->start($template, $ids, $this->access->permissions($request), $this->access->userId($request), $request->ip());

        return response()->json(['data' => DocumentGenerationBatchController::presentBatch($batch)], 201);
    }

    private function find(string $id): DocumentTemplate
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $this->templates->ensureDefaults();
        $t = DocumentTemplate::query()->find($id);
        abort_unless($t instanceof DocumentTemplate, 404);

        return $t;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DocumentTemplate $t, bool $detail): array
    {
        $draft = $this->templates->draft($t);
        $current = $this->templates->current($t);
        $shown = $draft ?? $current;
        $versions = $detail ? DocumentTemplateVersion::query()->where('document_template_id', $t->id)->orderByDesc('version')->get()
            ->map(fn (DocumentTemplateVersion $v): array => ['version' => $v->version, 'published_at' => $v->published_at?->toIso8601String(),
                'is_current' => $v->id === $t->current_version_id, 'variables' => $v->variables ?? [], 'created_at' => $v->created_at->toIso8601String(),
                'documents' => DB::table('documents')->where('document_template_version_id', $v->id)->whereNull('deleted_at')->count()])->all() : null;

        return [
            'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'description' => $t->description, 'kind' => $t->kind,
            'category_id' => $t->document_category_id,
            'category_name' => $t->document_category_id === null ? null : DB::table('document_categories')->where('id', $t->document_category_id)->value('name'),
            'legal_entity_id' => $t->legal_entity_id,
            'legal_entity_name' => $t->legal_entity_id === null ? null : DB::table('legal_entities')->where('id', $t->legal_entity_id)->value('legal_name'),
            'locale' => $t->locale, 'is_system' => $t->is_system, 'status' => $t->status,
            'body' => $shown->body ?? '', 'variables' => $shown->variables ?? [], 'page_settings' => $shown?->page_settings,
            'has_draft' => $draft !== null, 'current_version' => $current?->version, 'published_at' => $current?->published_at?->toIso8601String(),
            'versions' => $versions, 'allowed_actions' => $this->templates->allowedActions($t), 'version' => $t->lock_version,
            'created_at' => $t->created_at->toIso8601String(), 'updated_at' => $t->updated_at->toIso8601String(),
        ];
    }
}
