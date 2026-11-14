<?php

namespace App\Modules\Documents\Application;

use App\Modules\Documents\Domain\DocumentStateMachine;
use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Modules\Documents\Infrastructure\Models\DocumentVersion;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\VersionConflictException;
use App\Shared\Files\FileService;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DOC-1 · DOC-2 · Casos de uso del legajo digital (ME-12).
 *
 * - Subir: el archivo va al almacenamiento (FileService) con la clasificación del documento; nace Vigente, salvo que
 *   lo suba el propio titular en un tipo que exige revisión (En revisión). Un tipo con vencimiento exige la fecha.
 * - Versiones inmutables (RN-DOC-01): una versión nueva se agrega con sus propias fechas y queda como vigente; las
 *   anteriores siguen consultables. RN-DOC-02: un documento firmado no se reemplaza.
 * - Los documentos que generan otros módulos (contratos, adendas) se ven en el legajo pero se gestionan en su módulo:
 *   no se editan, versionan, archivan ni borran aquí (409 managed_by_module).
 * - Vencimientos (RN-DOC-09): la tarea diaria avisa una vez dentro de la anticipación del tipo (o del parámetro
 *   documentos.aviso_vencimiento_dias) y pasa a Vencido al llegar la fecha; los avisos salen por el outbox.
 * - Papelera: solo lo subido por error; se restaura desde la papelera y se depura según la conservación legal.
 */
final class DocumentService
{
    private const RANK = ['public' => 0, 'internal' => 1, 'confidential' => 2, 'restricted' => 3];

    /** @var array<string, int> */
    private array $noticeMemo = [];

    public function __construct(
        private readonly DocumentStateMachine $machine,
        private readonly DocumentCatalog $catalog,
        private readonly FileService $files,
        private readonly AuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly ParameterResolver $parameters,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function upload(array $data, UploadedFile $file, ?string $userId, ?string $uploaderPerson, bool $canClassify): Document
    {
        $category = $this->activeCategory((string) $data['category_id']);
        $employment = null;
        if (isset($data['employment_id'])) {
            $employment = DB::table('employments')->where('id', $data['employment_id'])->whereNull('deleted_at')->first(['id', 'person_id', 'legal_entity_id']);
            if ($employment === null) {
                throw ValidationException::withMessages(['employment_id' => [(string) trans('documents.documents.owner_unknown')]]);
            }
        } elseif (! isset($data['legal_entity_id'])) {
            throw ValidationException::withMessages(['employment_id' => [(string) trans('documents.documents.owner_required')]]);
        }
        $issued = isset($data['issued_on']) ? (string) $data['issued_on'] : null;
        $expires = isset($data['expires_on']) ? (string) $data['expires_on'] : null;
        $this->validateDates($category, $issued, $expires);
        $classification = $this->classification($category, isset($data['classification']) ? (string) $data['classification'] : null, $canClassify);
        $byTitular = $employment !== null && $uploaderPerson !== null && (string) $employment->person_id === $uploaderPerson;
        $status = $byTitular && $category->requires_review ? DocumentStateMachine::PENDING : DocumentStateMachine::ACTIVE;
        if ($status === DocumentStateMachine::ACTIVE && $expires !== null && $expires < now()->toDateString()) {
            $status = DocumentStateMachine::EXPIRED;
        }
        $visible = $byTitular || (isset($data['visible_to_employee']) ? self::bool($data['visible_to_employee']) : $category->visible_to_employee);
        $responsible = isset($data['responsible_user_id']) ? $this->responsible((string) $data['responsible_user_id']) : ($byTitular ? null : $userId);

        return DB::transaction(function () use ($data, $file, $userId, $category, $employment, $issued, $expires, $classification, $status, $visible, $responsible): Document {
            $doc = new Document([
                'document_category_id' => $category->id, 'title' => (string) $data['title'], 'description' => $data['description'] ?? null,
                'owner_person_id' => $employment?->person_id, 'employment_id' => $employment?->id,
                'legal_entity_id' => $employment !== null ? $employment->legal_entity_id : $data['legal_entity_id'],
                'classification' => $classification, 'issued_on' => $issued, 'expires_on' => $expires, 'source' => 'upload',
                'visible_to_employee' => $visible, 'responsible_user_id' => $responsible,
            ]);
            $doc->status = $status;
            $doc->save();
            $this->storeVersion($doc, $file, $userId, $issued, $expires, null);
            $this->audit->record('document.uploaded', 'document', $doc->id, null, $this->auditable($doc));

            return $doc->refresh();
        });
    }

    /**
     * RN-DOC-01 · Versión nueva: se agrega, no reemplaza; trae sus propias fechas y reinicia el aviso de vencimiento.
     */
    public function newVersion(Document $doc, UploadedFile $file, ?string $issued, ?string $expires, ?string $notes, ?string $userId, bool $byTitular): Document
    {
        $this->assertNotManaged($doc);
        if ($doc->source === 'signature') {
            throw new AyniException('signed_document', 409, (string) trans('documents.documents.signed'), [], ['current_status' => $doc->status, 'allowed_actions' => []]);
        }
        $category = DocumentCategory::query()->withTrashed()->findOrFail($doc->document_category_id);
        $issued ??= $doc->day('issued_on');
        $this->validateDates($category, $issued, $expires);
        $next = $this->machine->getNextState($doc->status, 'replace');
        if ($next === DocumentStateMachine::ACTIVE && $byTitular && $category->requires_review) {
            $next = DocumentStateMachine::PENDING;
        }
        if ($next === DocumentStateMachine::PENDING && ! ($byTitular && $category->requires_review)) {
            $next = DocumentStateMachine::ACTIVE;
        }
        if ($next === DocumentStateMachine::ACTIVE && $expires !== null && $expires < now()->toDateString()) {
            $next = DocumentStateMachine::EXPIRED;
        }

        return DB::transaction(function () use ($doc, $file, $issued, $expires, $notes, $userId, $next): Document {
            /** @var Document $locked */
            $locked = Document::query()->lockForUpdate()->findOrFail($doc->id);
            $before = $this->auditable($locked);
            $version = $this->storeVersion($locked, $file, $userId, $issued, $expires, $notes);
            DB::table('documents')->where('id', $locked->id)->update([
                'status' => $next, 'issued_on' => $issued, 'expires_on' => $expires, 'expiry_alerted_on' => null,
                'lock_version' => $locked->lock_version + 1, 'updated_at' => now(),
            ]);
            $locked->refresh();
            $this->audit->record('document.version_added', 'document', $locked->id, $before, $this->auditable($locked) + ['version_number' => $version]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Document $doc, array $data, ?int $expectedVersion, bool $canClassify): Document
    {
        $this->assertNotManaged($doc);
        if ($expectedVersion !== null && $expectedVersion !== $doc->lock_version) {
            throw new VersionConflictException($expectedVersion, $doc->lock_version);
        }
        $changes = array_intersect_key($data, array_flip(['title', 'description', 'visible_to_employee', 'responsible_user_id', 'category_id', 'classification']));
        if (array_key_exists('category_id', $changes)) {
            $changes['document_category_id'] = $this->activeCategory((string) $changes['category_id'])->id;
            unset($changes['category_id']);
        }
        $category = DocumentCategory::query()->withTrashed()->whereKey((string) ($changes['document_category_id'] ?? $doc->document_category_id))->firstOrFail();
        if (array_key_exists('classification', $changes) || isset($changes['document_category_id'])) {
            $changes['classification'] = $this->classification($category, (string) ($changes['classification'] ?? $doc->classification), $canClassify);
        }
        if (isset($changes['responsible_user_id'])) {
            $changes['responsible_user_id'] = $this->responsible((string) $changes['responsible_user_id']);
        }
        if (array_key_exists('visible_to_employee', $changes)) {
            $changes['visible_to_employee'] = self::bool($changes['visible_to_employee']);
        }

        return DB::transaction(function () use ($doc, $changes): Document {
            $before = $this->auditable($doc);
            $affected = DB::table('documents')->where('id', $doc->id)->where('lock_version', $doc->lock_version)
                ->update($changes + ['lock_version' => $doc->lock_version + 1, 'updated_at' => now()]);
            if ($affected === 0) {
                throw new VersionConflictException($doc->lock_version, (int) DB::table('documents')->where('id', $doc->id)->value('lock_version'));
            }
            $doc->refresh();
            if (isset($changes['classification'])) {
                $files = DB::table('document_versions')->where('document_id', $doc->id)->pluck('stored_file_id');
                DB::table('stored_files')->whereIn('id', $files)->update(['classification' => $changes['classification']]);
            }
            $this->audit->record('document.updated', 'document', $doc->id, $before, $this->auditable($doc));

            return $doc;
        });
    }

    public function approve(Document $doc, ?string $userId): Document
    {
        $this->assertNotManaged($doc);
        $this->transition($doc, 'approve', [], function () use ($doc): void {
            $this->outbox->record(DomainEventTypes::DOCUMENT_APPROVED, 'document', $doc->id, ['document_id' => $doc->id, 'category_id' => $doc->document_category_id, 'employment_id' => $doc->employment_id]);
        });
        if ($doc->expires_on !== null && $doc->day('expires_on') < now()->toDateString()) {
            $this->transition($doc, 'expire');
        }
        $this->audit->record('document.approved', 'document', $doc->id, ['status' => DocumentStateMachine::PENDING], ['status' => $doc->status]);

        return $doc;
    }

    public function reject(Document $doc, string $reason, ?string $userId): Document
    {
        $this->assertNotManaged($doc);
        $uploader = DB::table('document_versions')->where('id', $doc->current_version_id)->value('uploaded_by_user_id');
        $this->transition($doc, 'reject', [], function () use ($doc, $reason, $uploader): void {
            $this->outbox->record(DomainEventTypes::DOCUMENT_REJECTED, 'document', $doc->id, [
                'document_id' => $doc->id, 'category_id' => $doc->document_category_id, 'employment_id' => $doc->employment_id,
                'reason' => $reason, 'uploaded_by_user_id' => $uploader,
            ]);
        });
        $this->audit->record('document.rejected', 'document', $doc->id, ['status' => DocumentStateMachine::PENDING], ['status' => $doc->status], $reason);

        return $doc;
    }

    public function archive(Document $doc, ?string $reason): Document
    {
        $this->assertNotManaged($doc);
        $before = $doc->status;
        $this->transition($doc, 'archive', ['archived_at' => now(), 'archive_reason' => $reason], function () use ($doc): void {
            $this->outbox->record(DomainEventTypes::DOCUMENT_ARCHIVED, 'document', $doc->id, ['document_id' => $doc->id, 'category_id' => $doc->document_category_id, 'employment_id' => $doc->employment_id]);
        });
        $this->audit->record('document.archived', 'document', $doc->id, ['status' => $before], ['status' => $doc->status], $reason);

        return $doc;
    }

    /** Restaurar un archivado: vuelve a Vigente, o a Vencido si su fecha ya pasó. */
    public function restore(Document $doc): Document
    {
        $this->assertNotManaged($doc);
        $this->transition($doc, 'restore', ['archived_at' => null, 'archive_reason' => null]);
        if ($doc->expires_on !== null && $doc->day('expires_on') < now()->toDateString()) {
            $this->transition($doc, 'expire');
        }
        $this->audit->record('document.restored', 'document', $doc->id, ['status' => DocumentStateMachine::ARCHIVED], ['status' => $doc->status]);

        return $doc;
    }

    /** A la papelera (borrado lógico): solo lo que se subió al legajo. */
    public function trash(Document $doc): void
    {
        $this->assertNotManaged($doc);
        if ($doc->source !== 'upload') {
            throw new AyniException('managed_by_module', 409, (string) trans('documents.documents.not_deletable'), [], ['current_status' => $doc->status, 'allowed_actions' => []]);
        }
        DB::transaction(function () use ($doc): void {
            $doc->delete();
            $this->audit->record('document.trashed', 'document', $doc->id, $this->auditable($doc), null);
        });
    }

    /**
     * RN-DOC-09 · Tarea diaria: aviso único dentro de la anticipación y paso a Vencido al llegar la fecha.
     *
     * @return array{expiring: int, expired: int}
     */
    public function checkExpiry(string $date): array
    {
        $today = CarbonImmutable::parse($date)->startOfDay();
        $counts = ['expiring' => 0, 'expired' => 0];
        /** @var list<Document> $docs */
        $docs = Document::query()->where('status', DocumentStateMachine::ACTIVE)->whereNotNull('expires_on')
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', 'document'))
            ->where('expires_on', '<=', $today->addDays(366)->toDateString())->orderBy('expires_on')->get()->all();
        foreach ($docs as $doc) {
            $expires = (string) $doc->day('expires_on');
            $payload = ['document_id' => $doc->id, 'category_id' => $doc->document_category_id, 'employment_id' => $doc->employment_id, 'expires_on' => $expires];
            if ($expires < $today->toDateString()) {
                $this->transition($doc, 'expire', [], function () use ($doc, $payload): void {
                    $this->outbox->record(DomainEventTypes::DOCUMENT_EXPIRED, 'document', $doc->id, $payload);
                });
                $this->audit->record('document.expired', 'document', $doc->id, ['status' => DocumentStateMachine::ACTIVE], ['status' => DocumentStateMachine::EXPIRED]);
                $counts['expired']++;

                continue;
            }
            $daysLeft = (int) $today->diffInDays(CarbonImmutable::parse($expires), false);
            if ($doc->expiry_alerted_on !== null || $daysLeft > $this->noticeDays($doc)) {
                continue;
            }
            DB::transaction(function () use ($doc, $payload, $daysLeft, $today): void {
                $affected = DB::table('documents')->where('id', $doc->id)->whereNull('expiry_alerted_on')->update(['expiry_alerted_on' => $today->toDateString()]);
                if ($affected === 1) {
                    $this->outbox->record(DomainEventTypes::DOCUMENT_EXPIRING, 'document', $doc->id, $payload + ['days_left' => $daysLeft]);
                }
            });
            $counts['expiring']++;
        }

        return $counts;
    }

    /** Anticipación del aviso: la del tipo, la de su carpeta o el parámetro documentos.aviso_vencimiento_dias. */
    public function noticeDays(Document $doc): int
    {
        $key = $doc->document_category_id.'|'.$doc->legal_entity_id;
        if (isset($this->noticeMemo[$key])) {
            return $this->noticeMemo[$key];
        }
        $category = DocumentCategory::query()->withTrashed()->find($doc->document_category_id);
        $days = $category?->expiry_notice_days;
        if ($days === null && $category instanceof DocumentCategory) {
            $days = $this->catalog->folder($category)->expiry_notice_days;
        }
        if ($days === null) {
            $raw = $this->parameters->value('documentos.aviso_vencimiento_dias', $doc->legal_entity_id);
            $days = is_numeric($raw) ? (int) $raw : 0;
        }

        return $this->noticeMemo[$key] = max(0, (int) $days);
    }

    public function version(Document $doc, ?int $number): ?DocumentVersion
    {
        $q = DocumentVersion::query()->where('document_id', $doc->id);
        $v = $number === null ? $q->where('id', $doc->current_version_id)->first() : $q->where('version', $number)->first();

        return $v instanceof DocumentVersion ? $v : null;
    }

    /**
     * @return array{name: string, mime: string, contents: string}
     */
    public function contents(DocumentVersion $version, ?string $userId): array
    {
        return $this->files->contentsForDownload($version->stored_file_id, null, $userId);
    }

    public function assertNotManaged(Document $doc): void
    {
        $module = $doc->managedBy();
        if ($module !== null) {
            throw new AyniException('managed_by_module', 409, (string) trans('documents.documents.managed', ['module' => (string) trans('documents.modules.'.$module)]), [], [
                'current_status' => $doc->status, 'allowed_actions' => [], 'managed_by' => $module,
            ]);
        }
    }

    /**
     * Transición de estado validada por la máquina (409 invalid_transition) con bloqueo optimista: un solo UPDATE
     * con la versión leída (el trigger de la tabla incrementa lock_version y updated_at).
     *
     * @param  array<string, mixed>  $extra
     */
    private function transition(Document $doc, string $action, array $extra = [], ?callable $effect = null): void
    {
        $next = $this->machine->getNextState($doc->status, $action);
        DB::transaction(function () use ($doc, $next, $extra, $effect): void {
            $affected = DB::table('documents')->where('id', $doc->id)->where('lock_version', $doc->lock_version)->update(['status' => $next] + $extra);
            if ($affected === 0) {
                throw new VersionConflictException($doc->lock_version, (int) DB::table('documents')->where('id', $doc->id)->value('lock_version'));
            }
            if ($effect !== null) {
                $effect();
            }
        });
        $doc->refresh();
    }

    private function storeVersion(Document $doc, UploadedFile $file, ?string $userId, ?string $issued, ?string $expires, ?string $notes): int
    {
        $contents = (string) file_get_contents((string) $file->getRealPath());
        $stored = $this->files->upload($contents, $file->getClientOriginalName(), (string) ($file->getMimeType() ?? $file->getClientMimeType()),
            TenancyScope::requireTenant(), $userId, $doc->classification);
        $number = (int) DB::table('document_versions')->where('document_id', $doc->id)->max('version') + 1;
        $version = DocumentVersion::create([
            'document_id' => $doc->id, 'version' => $number, 'stored_file_id' => $stored->id, 'content_hash' => $stored->sha256,
            'uploaded_by_user_id' => $userId, 'notes' => $notes, 'issued_on' => $issued, 'expires_on' => $expires,
        ]);
        DB::table('documents')->where('id', $doc->id)->update(['current_version_id' => $version->id]);
        $doc->current_version_id = $version->id;

        return $number;
    }

    private function activeCategory(string $id): DocumentCategory
    {
        $this->catalog->ensureDefaults();
        $c = preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? DocumentCategory::query()->where('status', 'active')->find($id) : null;
        if (! $c instanceof DocumentCategory) {
            throw ValidationException::withMessages(['category_id' => [(string) trans('documents.documents.category_unknown')]]);
        }
        if ($c->code === 'contracts' || $this->catalog->folder($c)->code === 'contracts') {
            // Los contratos y adendas se generan y firman en su módulo (CON-2, CON-3).
            throw ValidationException::withMessages(['category_id' => [(string) trans('documents.documents.category_managed')]]);
        }

        return $c;
    }

    private function validateDates(DocumentCategory $category, ?string $issued, ?string $expires): void
    {
        if ($category->requires_expiry && $expires === null) {
            throw ValidationException::withMessages(['expires_on' => [(string) trans('documents.documents.expiry_required')]]);
        }
        if ($issued !== null && $expires !== null && $expires < $issued) {
            throw ValidationException::withMessages(['expires_on' => [(string) trans('documents.documents.expiry_before_issue')]]);
        }
    }

    /** La clasificación no baja de la del tipo, salvo para quien ve confidenciales. */
    private function classification(DocumentCategory $category, ?string $requested, bool $canClassify): string
    {
        $default = $category->default_classification;
        if ($requested === null) {
            return $default;
        }
        if (! $canClassify && self::RANK[$requested] < self::RANK[$default]) {
            throw ValidationException::withMessages(['classification' => [(string) trans('documents.documents.classification_lower')]]);
        }

        return $requested;
    }

    private function responsible(string $userId): string
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $userId) !== 1 || ! DB::table('users')->where('id', $userId)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['responsible_user_id' => [(string) trans('documents.documents.responsible_unknown')]]);
        }

        return $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditable(Document $doc): array
    {
        return [
            'title' => $doc->title, 'category_id' => $doc->document_category_id, 'employment_id' => $doc->employment_id,
            'legal_entity_id' => $doc->legal_entity_id, 'classification' => $doc->classification, 'status' => $doc->status,
            'issued_on' => $doc->day('issued_on'), 'expires_on' => $doc->day('expires_on'), 'visible_to_employee' => $doc->visible_to_employee,
            'responsible_user_id' => $doc->responsible_user_id, 'current_version_id' => $doc->current_version_id,
        ];
    }

    private static function bool(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'true'], true);
    }
}
