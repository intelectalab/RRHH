<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOC-1 · Carpetas del legajo y tipos documentales. Las carpetas del sistema (personal, contratos, boletas,
 * certificados, vacaciones y ausencias, SST, evaluaciones y capacitaciones) se crean la primera vez que se usan
 * en la organización; la de contratos es la misma que ya usa el módulo de contratos (sin duplicar). Cada tipo
 * define su confidencialidad, si el colaborador lo ve, si lo que él sube pasa por revisión y si exige vencimiento
 * con su anticipación de aviso (RN-DOC-09; sin anticipación propia rige documentos.aviso_vencimiento_dias).
 * El nombre de las carpetas y tipos del sistema se traduce (lang documents.folders / documents.types).
 */
final class DocumentCatalog
{
    public const CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];

    /**
     * Carpetas del sistema: código => [orden, clasificación por defecto, visible para el colaborador, permiso extra].
     *
     * @var array<string, array{0: int, 1: string, 2: bool, 3: string|null}>
     */
    private const FOLDERS = [
        'personal' => [10, 'internal', true, null],
        'contracts' => [20, 'confidential', false, 'contracts.contracts.view'],
        'payslips' => [30, 'confidential', true, null],
        'certificates' => [40, 'confidential', true, null],
        'vacations' => [50, 'internal', true, null],
        'sst' => [60, 'internal', true, null],
        'evaluations' => [70, 'confidential', false, null],
        'trainings' => [80, 'internal', true, null],
    ];

    /**
     * Tipos del sistema: código => [carpeta, clasificación, visible, revisión, exige vencimiento].
     *
     * @var array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool}>
     */
    private const TYPES = [
        'identity_document' => ['personal', 'internal', true, true, true],
        'resume' => ['personal', 'internal', true, false, false],
        'academic_degree' => ['personal', 'internal', true, true, false],
        'background_certificate' => ['personal', 'confidential', true, true, true],
        'work_certificate' => ['certificates', 'internal', true, false, false],
        'medical_certificate' => ['certificates', 'restricted', true, true, false],
        'occupational_exam' => ['sst', 'restricted', true, false, true],
        'sst_induction' => ['sst', 'internal', true, false, false],
        'ppe_delivery' => ['sst', 'internal', true, false, false],
        'training_certificate' => ['trainings', 'internal', true, true, true],
    ];

    private bool $ensured = false;

    public function __construct(private readonly AuditLogger $audit) {}

    /** Crea las carpetas y tipos del sistema que falten (idempotente). */
    public function ensureDefaults(): void
    {
        if ($this->ensured) {
            return;
        }
        $tenant = TenancyScope::requireTenant();
        $existing = DB::table('document_categories')->whereNull('deleted_at')->pluck('id', 'code');
        if (count(array_intersect(array_keys(self::FOLDERS + self::TYPES), $existing->keys()->all())) < count(self::FOLDERS + self::TYPES)
            || DB::table('document_categories')->where('code', 'contracts')->whereNull('deleted_at')->where('is_system', false)->exists()) {
            foreach (self::FOLDERS as $code => [$order, $classification, $visible, $permission]) {
                DB::table('document_categories')->insertOrIgnore([
                    'tenant_id' => $tenant, 'code' => $code, 'name' => (string) trans('documents.folders.'.$code, [], 'es'),
                    'default_classification' => $classification, 'visible_to_employee' => $visible, 'required_permission_code' => $permission,
                    'is_system' => true, 'sort_order' => $order, 'scope' => 'employee',
                ]);
                // La carpeta de contratos pudo nacer antes, desde el módulo de contratos: pasa a ser del sistema.
                DB::table('document_categories')->where('code', $code)->whereNull('deleted_at')->whereNull('parent_id')->where('is_system', false)
                    ->update(['is_system' => true, 'sort_order' => $order, 'updated_at' => now()]);
            }
            $folders = DB::table('document_categories')->whereNull('deleted_at')->whereNull('parent_id')->pluck('id', 'code');
            $i = 0;
            foreach (self::TYPES as $code => [$folder, $classification, $visible, $review, $expiry]) {
                $i++;
                if (! isset($folders[$folder])) {
                    continue;
                }
                DB::table('document_categories')->insertOrIgnore([
                    'tenant_id' => $tenant, 'code' => $code, 'name' => (string) trans('documents.types.'.$code, [], 'es'), 'parent_id' => $folders[$folder],
                    'default_classification' => $classification, 'visible_to_employee' => $visible, 'requires_review' => $review,
                    'requires_expiry' => $expiry, 'is_system' => true, 'sort_order' => $i * 10, 'scope' => 'employee',
                ]);
            }
        }
        $this->ensured = true;
    }

    /**
     * Carpetas con sus tipos, en orden.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(bool $includeArchived): array
    {
        $this->ensureDefaults();
        /** @var list<DocumentCategory> $all */
        $all = DocumentCategory::query()->when(! $includeArchived, fn ($q) => $q->where('status', 'active'))
            ->orderBy('sort_order')->orderBy('name')->get()->all();
        $out = [];
        foreach ($all as $c) {
            if ($c->parent_id === null) {
                $out[$c->id] = $this->present($c) + ['types' => []];
            }
        }
        foreach ($all as $c) {
            if ($c->parent_id !== null && isset($out[$c->parent_id])) {
                $out[$c->parent_id]['types'][] = $this->present($c);
            }
        }

        return array_values($out);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(DocumentCategory $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $this->name($c),
            'parent_id' => $c->parent_id,
            'description' => $c->description,
            'default_classification' => $c->default_classification,
            'required_permission_code' => $c->required_permission_code,
            'scope' => $c->scope,
            'visible_to_employee' => $c->visible_to_employee,
            'requires_review' => $c->requires_review,
            'requires_expiry' => $c->requires_expiry,
            'expiry_notice_days' => $c->expiry_notice_days,
            'is_system' => $c->is_system,
            'sort_order' => $c->sort_order,
            'status' => $c->status,
            'allowed_actions' => $c->is_system ? [] : ($c->status === 'active' ? ['archive'] : ['restore']),
        ];
    }

    public function name(DocumentCategory $c): string
    {
        if ($c->is_system) {
            $key = ($c->parent_id === null ? 'documents.folders.' : 'documents.types.').$c->code;
            $text = trans($key);
            if (is_string($text) && $text !== $key) {
                return $text;
            }
        }

        return $c->name;
    }

    public function find(string $id): DocumentCategory
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);
        $c = DocumentCategory::query()->find($id);
        abort_unless($c instanceof DocumentCategory, 404);

        return $c;
    }

    /** Carpeta del tipo (o el propio tipo si es una carpeta). */
    public function folder(DocumentCategory $c): DocumentCategory
    {
        if ($c->parent_id === null) {
            return $c;
        }
        $parent = DocumentCategory::query()->withTrashed()->find($c->parent_id);

        return $parent instanceof DocumentCategory ? $parent : $c;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): DocumentCategory
    {
        $this->ensureDefaults();
        $parent = null;
        if (isset($data['parent_id'])) {
            $parent = $this->find((string) $data['parent_id']);
            if ($parent->parent_id !== null) {
                throw ValidationException::withMessages(['parent_id' => [(string) trans('documents.categories.parent_not_folder')]]);
            }
        }
        if (DocumentCategory::query()->where('code', $data['code'])->exists()) {
            throw new AyniException('duplicate', 409, (string) trans('documents.categories.code_taken'));
        }
        $this->validatePermission($data);

        return DB::transaction(function () use ($data, $parent): DocumentCategory {
            $c = DocumentCategory::create([
                'code' => $data['code'], 'name' => $data['name'], 'parent_id' => $parent?->id,
                'description' => $data['description'] ?? null,
                'default_classification' => $data['default_classification'] ?? ($parent->default_classification ?? 'internal'),
                'required_permission_code' => $data['required_permission_code'] ?? null,
                'scope' => $data['scope'] ?? ($parent->scope ?? 'employee'),
                'visible_to_employee' => (bool) ($data['visible_to_employee'] ?? ($parent->visible_to_employee ?? false)),
                'requires_review' => (bool) ($data['requires_review'] ?? false),
                'requires_expiry' => (bool) ($data['requires_expiry'] ?? false),
                'expiry_notice_days' => $data['expiry_notice_days'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 100),
                'is_system' => false,
            ]);
            $this->audit->record('document_category.created', 'document_category', $c->id, null, $c->only(array_keys($data)));

            return $c;
        });
    }

    /**
     * Los tipos del sistema solo cambian su comportamiento (visibilidad, revisión, vencimiento, confidencialidad);
     * su código y su carpeta no cambian.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DocumentCategory $c, array $data): DocumentCategory
    {
        if ($c->is_system) {
            unset($data['name'], $data['code'], $data['scope']);
        }
        unset($data['code'], $data['parent_id']);
        $this->validatePermission($data);
        if ($c->is_system && $c->code === 'contracts') {
            // La carpeta de contratos conserva el permiso del módulo de contratos.
            unset($data['required_permission_code']);
        }
        $before = $c->only(array_keys($data));
        DB::transaction(function () use ($c, $data, $before): void {
            $c->fill($data);
            $c->save();
            $this->audit->record('document_category.updated', 'document_category', $c->id, $before, $c->only(array_keys($data)));
        });

        return $c;
    }

    public function archive(DocumentCategory $c): DocumentCategory
    {
        return $this->setStatus($c, 'active', 'archived', 'archive');
    }

    public function restore(DocumentCategory $c): DocumentCategory
    {
        return $this->setStatus($c, 'archived', 'active', 'restore');
    }

    private function setStatus(DocumentCategory $c, string $from, string $to, string $action): DocumentCategory
    {
        if ($c->is_system || $c->status !== $from) {
            throw new AyniException('invalid_transition', 409, (string) trans($c->is_system ? 'documents.categories.system' : 'documents.categories.invalid_transition'), [], [
                'current_status' => $c->status, 'allowed_actions' => $this->present($c)['allowed_actions'],
            ]);
        }
        DB::transaction(function () use ($c, $from, $to, $action): void {
            $c->status = $to;
            $c->save();
            $this->audit->record('document_category.'.($action === 'archive' ? 'archived' : 'restored'), 'document_category', $c->id, ['status' => $from], ['status' => $to]);
        });

        return $c;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validatePermission(array $data): void
    {
        $code = $data['required_permission_code'] ?? null;
        if (is_string($code) && ! DB::table('permissions')->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['required_permission_code' => [(string) trans('documents.categories.permission_unknown')]]);
        }
    }
}
