<?php

namespace App\Modules\Documents\Infrastructure\Trash;

use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Shared\Audit\AuditLogger;
use App\Shared\Files\FileService;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Trash\PurgesSafely;
use App\Shared\Trash\TrashSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * DOC-1 · Documentos del legajo movidos a la papelera (los subidos por error). Se restauran con sus versiones
 * intactas. La depuración definitiva respeta la conservación legal del legajo (parámetro legal
 * legajo_conservacion_anios, por validar): mientras no tenga un valor validado no se depura nada, y con valor
 * solo se depura lo que superó ese plazo además del de la papelera.
 * De los documentos confidenciales la papelera muestra el tipo, no el título.
 */
final class DocumentTrashSource implements TrashSource
{
    use PurgesSafely;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FileService $files,
        private readonly LegalParameters $legal,
    ) {}

    public function type(): string
    {
        return 'document';
    }

    public function permission(): string
    {
        return 'documents.documents.restore';
    }

    public function items(?string $search, int $limit): array
    {
        $q = Document::onlyTrashed()->orderByDesc('deleted_at');
        if ($search !== null && $search !== '') {
            $q->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%')->whereNotIn('classification', ['confidential', 'restricted']);
        }
        $categories = DocumentCategory::query()->withTrashed()->pluck('name', 'id');

        return $q->limit($limit)->get()->map(function (Document $d) use ($categories): array {
            $type = (string) ($categories[$d->document_category_id] ?? '');
            $confidential = in_array($d->classification, ['confidential', 'restricted'], true);

            return [
                'id' => $d->id,
                'title' => $confidential ? $type : $d->title,
                'subtitle' => $confidential ? null : $type,
                'deleted_at' => (string) $d->deleted_at?->toIso8601String(),
            ];
        })->all();
    }

    public function owns(string $id): bool
    {
        return Document::onlyTrashed()->whereKey($id)->exists();
    }

    public function restore(string $id): void
    {
        /** @var Document $doc */
        $doc = Document::onlyTrashed()->whereKey($id)->firstOrFail();
        DB::transaction(function () use ($doc): void {
            $doc->restore();
            $this->audit->record('document.restored_from_trash', 'document', $doc->id, null, ['title' => $doc->title, 'status' => $doc->status]);
        });
    }

    public function expired(\DateTimeInterface $deletedBefore): array
    {
        $years = $this->retentionYears();
        if ($years === null) {
            return [];
        }
        $limit = CarbonImmutable::instance($deletedBefore);
        $legal = CarbonImmutable::now()->subYears($years);

        return Document::onlyTrashed()->where('deleted_at', '<', $limit->min($legal))
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    public function purge(string $id): bool
    {
        /** @var Document|null $doc */
        $doc = Document::onlyTrashed()->whereKey($id)->first();
        if ($doc === null) {
            return true;
        }
        $files = DB::table('document_versions')->where('document_id', $doc->id)->pluck('stored_file_id')->map(fn ($f): string => (string) $f)->all();

        return $this->purgeSafely(function () use ($doc): void {
            DB::table('documents')->where('id', $doc->id)->update(['current_version_id' => null]);
            DB::table('document_versions')->where('document_id', $doc->id)->delete();
            $doc->forceDelete();
            $this->audit->record('document.purged', 'document', $doc->id, ['title' => $doc->title, 'category_id' => $doc->document_category_id], null);
        }, function () use ($files): void {
            foreach ($files as $file) {
                $this->files->delete($file);
            }
        });
    }

    /** Años de conservación validados; null mientras el parámetro esté por validar. */
    private function retentionYears(): ?int
    {
        $country = (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
        $p = $this->legal->at($country, 'legajo_conservacion_anios');
        if ($p === null || $p['pending_validation'] || ! is_numeric($p['value'])) {
            return null;
        }

        return max(0, (int) $p['value']);
    }
}
